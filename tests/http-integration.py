"""Exercise real PHP upload/stream handlers with a local WordPress API fixture."""
import io, json, os, shutil, shlex, socket, subprocess, tempfile, time, urllib.request, urllib.error, urllib.parse, zipfile
from pathlib import Path
root = Path(__file__).resolve().parents[1]
class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, req, fp, code, msg, headers, newurl): return None
opener = urllib.request.build_opener(NoRedirect)
def multipart(values, archive):
    boundary='REXBOUNDARY123456'
    parts=[]
    for key,value in values.items():
        parts.append(f'--{boundary}\r\nContent-Disposition: form-data; name="{key}"\r\n\r\n{value}\r\n'.encode())
    parts.append(f'--{boundary}\r\nContent-Disposition: form-data; name="archive"; filename="export.zip"\r\nContent-Type: application/zip\r\n\r\n'.encode()+archive+b'\r\n')
    parts.append(f'--{boundary}--\r\n'.encode())
    return b''.join(parts), 'multipart/form-data; boundary='+boundary
with tempfile.TemporaryDirectory(prefix='rex-http-') as fixture:
    with socket.socket() as s: s.bind(('127.0.0.1',0)); port=s.getsockname()[1]
    env=os.environ.copy();env['REX_TEST_DIR']=fixture
    command=shlex.split(env.get('REX_TEST_PHP_COMMAND','php'))
    with tempfile.TemporaryFile() as log:
        server=subprocess.Popen(command+['-S',f'127.0.0.1:{port}',str(root/'tests/http-fixture.php')],env=env,stdout=log,stderr=log)
        def request(action, site='A', auth='admin', fields=None, archive=None, **query):
            params={'test_action':action,'site':site,'auth':auth,**query}
            url=f'http://127.0.0.1:{port}/?'+urllib.parse.urlencode(params)
            data=None;headers={}
            if archive is not None: data,headers['Content-Type']=multipart(fields or {},archive)
            elif fields is not None: data=urllib.parse.urlencode(fields,doseq=True).encode();headers['Content-Type']='application/x-www-form-urlencoded'
            try:
                response=opener.open(urllib.request.Request(url,data=data,headers=headers),timeout=15)
                return response.status,response.read(),response.headers
            except urllib.error.HTTPError as e: return e.code,e.read(),e.headers
        try:
            for _ in range(50):
                try: request('state');break
                except urllib.error.URLError: time.sleep(.1)
            else: raise AssertionError('Server failed to start')
            status,admin_html,_=request('admin')
            assert status==200 and b'name="version"' in admin_html and b'name="access_mode"' in admin_html
            for page in ['manager','dashboard','trash','requests','integrity']:
                code,html,_=request(page);assert code==200 and b'Fatal error' not in html,(page,html)
            status,front_html,_=request('frontend')
            assert status==200 and b'rex-toolbar' in front_html and b'rex-favorite' in front_html
            status,pdf,headers=request('preview',auth='tech',id=1,_wpnonce='valid')
            assert status==200 and pdf.startswith(b'%PDF-') and headers['Content-Type']=='application/pdf',(status,pdf)
            assert 'inline' in headers['Content-Disposition']
            assert request('preview',auth='user',id=1,_wpnonce='valid')[0]==403
            assert request('preview',auth='tech',id=1,_wpnonce='bad')[0]==403
            status,file,headers=request('download',auth='tech',id=1,_wpnonce='valid')
            assert status==200 and file==pdf and 'attachment' in headers['Content-Disposition']
            assert request('download',auth='user',id=1,_wpnonce='valid')[0]==403
            status,result,_=request('favorite',auth='tech',fields={'nonce':'valid','id':1,'active':1})
            assert status==200 and json.loads(result)['success']
            assert request('favorite',auth='user',fields={'nonce':'valid','id':1,'active':1})[0]==403
            status,result,_=request('save',fields={'_wpnonce':'valid','media_ids':'[101,102]','title':'Bulk','categories[]':[1],'roles[]':['rex_tech'],'access_mode':'inherit','version':'2.0','changelog':'Bulk import'})
            assert status==302,(status,result)
            state=json.loads(request('state')[1]);downloads=[p for p in state['posts'].values() if p['post_type']=='rex_download']
            assert len(downloads)==3
            assert sorted(p['post_title'] for p in downloads)==['Private PDF','firmware.zip','manual.pdf']
            assert len(state['events'])==2 and {e['event_type'] for e in state['events']}=={'preview','download'}
            status,archive,_=request('export',fields={'_wpnonce':'valid'})
            assert status==200,(status,archive)
            with zipfile.ZipFile(io.BytesIO(archive)) as z:
                manifest=json.loads(z.read('manifest.json'))
                assert len(manifest['downloads'])==3 and 'users' not in manifest
                entries={name:z.read(name) for name in z.namelist()}
            request('collision',site='B')
            status,result,_=request('import',site='B',fields={'_wpnonce':'valid','import_settings':'1'},archive=archive)
            assert status==302,(status,result)
            state=json.loads(request('state',site='B')[1])
            assert len(state['posts'])==3 and not state['events']
            # Existing broad category must not widen the imported restricted file's access.
            pdf_id=next(p['ID'] for p in state['posts'].values() if p['post_title']=='Private PDF')
            assert state['meta'][str(pdf_id)]['_rex_access_mode']=='explicit'
            assert request('download',site='B',auth='user',id=pdf_id,_wpnonce='valid')[0]==403
            assert request('download',site='B',auth='tech',id=pdf_id,_wpnonce='valid')[1]==pdf
            assert request('import',site='B',fields={'_wpnonce':'valid'},archive=archive)[0]==302
            assert len(json.loads(request('state',site='B')[1])['posts'])==3
            manifest['downloads'][0]['sha256']='0'*64
            entries['manifest.json']=json.dumps(manifest).encode()
            damaged=io.BytesIO()
            with zipfile.ZipFile(damaged,'w') as z:
                for name,contents in entries.items(): z.writestr(name,contents)
            assert request('import',site='B',fields={'_wpnonce':'valid'},archive=damaged.getvalue())[0]==400
            assert len(json.loads(request('state',site='B')[1])['posts'])==3
            assert request('export',auth='user',fields={'_wpnonce':'valid'})[0]==403
            assert request('import',site='B',fields={'_wpnonce':'bad'},archive=archive)[0]==403
            # Old binaries are preserved and can be promoted without losing the replacement.
            assert request('save',fields={'_wpnonce':'valid','id':1,'title':'Private PDF','media_id':102,'categories[]':[1],'roles[]':['rex_tech'],'access_mode':'inherit','version':'3.0','notify_subscribers':1})[0]==302
            state=json.loads(request('state')[1]); old=state['meta']['1']['_rex_file_versions'][0]
            assert request('version_download',id=1,key=old['key'],_wpnonce='valid')[1]==pdf
            assert request('version_download',auth='user',id=1,key=old['key'],_wpnonce='valid')[0]==403
            assert request('restore_version',id=1,key=old['key'],_wpnonce='valid')[0]==302
            restored=request('download',auth='tech',id=1,_wpnonce='valid'); assert restored[1]==pdf,(restored[:2],pdf)
            assert len(json.loads(request('state')[1])['meta']['1']['_rex_file_versions'])==1
            exported=request('export',fields={'_wpnonce':'valid'})[1]
            with zipfile.ZipFile(io.BytesIO(exported)) as z:
                data=json.loads(z.read('manifest.json')); entry=next(d for d in data['downloads'] if d['title']=='Private PDF');assert len(entry['archives'])==1;assert z.read(entry['archives'][0]['entry'])==b'Firmware'
                exported_entries={name:z.read(name) for name in z.namelist()}
            for index,download in enumerate(data['downloads']):download['uuid']=f'{index+500:08x}-9999-9999-9999-999999999999'
            exported_entries['manifest.json']=json.dumps(data).encode(); copy=io.BytesIO()
            with zipfile.ZipFile(copy,'w') as z:
                for name,contents in exported_entries.items():z.writestr(name,contents)
            assert request('import',site='B',fields={'_wpnonce':'valid'},archive=copy.getvalue())[0]==302
            imported=json.loads(request('state',site='B')[1]); imported_id=next(p['ID'] for p in imported['posts'].values() if imported['meta'][str(p['ID'])]['_rex_uuid']==entry['uuid'])
            saved_version=imported['meta'][str(imported_id)]['_rex_file_versions'][0]
            assert request('version_download',site='B',id=imported_id,key=saved_version['key'],_wpnonce='valid')[1]==b'Firmware'

            assert request('watch',auth='user',fields={'nonce':'valid','id':1,'kind':'file','active':1})[0]==403

            # Scheduling rejects invalid ranges before any replacement or metadata change.
            assert request('save',fields={'_wpnonce':'valid','id':1,'title':'Invalid','media_id':102,'publish_at':'2030-02-01T00:00','expire_at':'2029-01-01T00:00'})[0]==400
            assert json.loads(request('state')[1])['posts']['1']['post_title']=='Private PDF'
            assert request('bulk_files',fields={'_wpnonce':'valid','ids':'[1]','bulk_action':'schedule','publish_at':'2030-01-01T00:00'})[0]==302
            assert request('download',auth='tech',id=1,_wpnonce='valid')[0]==403
            assert request('bulk_files',fields={'_wpnonce':'valid','ids':'[1]','bulk_action':'schedule'})[0]==302
            assert request('request_access',auth='user',fields={'nonce':'bad','id':1})[0]==403
            result=json.loads(request('request_access',auth='user',fields={'nonce':'valid','id':1})[1]);assert result['success']
            state=json.loads(request('state')[1]); access=next(p['ID'] for p in state['posts'].values() if p['post_type']=='rex_access_request')
            assert request('decide_request',auth='user',fields={'_wpnonce':'valid','id':access,'operation':'approve'})[0]==403
            assert request('decide_request',fields={'_wpnonce':'valid','id':access,'operation':'approve'})[0]==302
            assert request('download',auth='user',id=1,_wpnonce='valid')[0]==200
            assert request('watch',auth='user',fields={'nonce':'valid','id':1,'kind':'file','active':1})[0]==200
            assert request('notify')[0]==200
            assert len(json.loads(request('state')[1])['mails'])==1
            request('notify');assert len(json.loads(request('state')[1])['mails'])==1
            assert request('decide_request',fields={'_wpnonce':'valid','id':access,'operation':'revoke'})[0]==302
            assert request('download',auth='user',id=1,_wpnonce='valid')[0]==403
            assert request('watch',auth='user',fields={'nonce':'valid','id':1,'kind':'file','active':0})[0]==200
            assert request('file_order',fields={'_wpnonce':'valid','category':1,'order':'[1]'})[0]==302
            assert request('file_order',fields={'_wpnonce':'valid','order':'[1,1]'})[0]==400
            assert request('delete',fields={'_wpnonce':'valid','id':1})[0]==302
            assert request('download',auth='tech',id=1,_wpnonce='valid')[0]==403
            assert json.loads(request('state')[1])['posts']['1']['post_status']=='rex_trash'
            assert request('trash_action',fields={'_wpnonce':'valid','id':1,'operation':'restore'})[0]==302
            restored=request('download',auth='tech',id=1,_wpnonce='valid'); assert restored[1]==pdf,(restored[:2],pdf)
            scan=json.loads(request('integrity_begin',fields={'nonce':'valid'})[1])['data']
            results=[]
            while True:
                batch=json.loads(request('integrity_batch',fields={'nonce':'valid','token':scan['token']})[1])['data'];results.extend(batch['issues'])
                if batch['done']:break
            assert not any('ontbreekt' in issue or 'beschadigd' in issue for issue in results)
            state=json.loads(request('state')[1]); key=state['meta']['1']['_rex_key']
            Path(fixture,'A','private',key).write_bytes(b'changed')
            scan=json.loads(request('integrity_begin',fields={'nonce':'valid'})[1])['data'];results=[]
            while True:
                batch=json.loads(request('integrity_batch',fields={'nonce':'valid','token':scan['token']})[1])['data'];results.extend(batch['issues'])
                if batch['done']:break
            assert any('beschadigd' in issue for issue in results)
            assert request('delete',fields={'_wpnonce':'valid','id':1})[0]==302
            assert request('trash_action',fields={'_wpnonce':'valid','id':1,'operation':'purge'})[0]==302
            assert not Path(fixture,'A','private',key).exists()
            assert not Path(fixture,'A','private',state['meta']['1']['_rex_file_versions'][0]['key']).exists()
            print('HTTP access, versions, archive export, scheduling, grants, subscriptions, integrity and trash passed.')
        except Exception:
            log.seek(0);print(log.read().decode(errors='replace'));raise
        finally:
            server.terminate();server.wait(timeout=5)
