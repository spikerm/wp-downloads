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
            print('HTTP downloads, PDF authorization, favorites, bulk import and ZIP roundtrip passed.')
        except Exception:
            log.seek(0);print(log.read().decode(errors='replace'));raise
        finally:
            server.terminate();server.wait(timeout=5)
