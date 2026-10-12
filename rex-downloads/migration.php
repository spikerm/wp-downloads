<?php
if (!defined('ABSPATH')) { exit; }
final class Rex_Downloads_Migration {
    private $core;
    public function __construct($core) {
        $this->core=$core;
        add_action('admin_menu',function(){add_submenu_page('rex-downloads','Export & import','Export & import','manage_options','rex-download-migration',[$this,'admin']);},25);
        add_action('admin_post_rex_export',[$this,'export']);
        add_action('admin_post_rex_import',[$this,'import']);
    }
    private function guard($nonce){if(!current_user_can('manage_options')){wp_die('Geen toegang.','',['response'=>403]);}check_admin_referer($nonce);}
    public static function media_source($id) {
        if(get_post_type($id)!=='attachment'||!current_user_can('edit_post',$id)){return new WP_Error('media','Geen toegang tot mediabestand #'.$id);}
        $source=get_attached_file($id);$source=is_string($source)?realpath($source):false;$uploads=wp_upload_dir();$base=realpath($uploads['basedir']);
        if(!$source||!$base||strpos(wp_normalize_path($source),trailingslashit(wp_normalize_path($base)))!==0||!is_file($source)||!is_readable($source)){return new WP_Error('media','Mediabestand #'.$id.' is niet lokaal beschikbaar.');}
        if(!in_array(strtolower(pathinfo($source,PATHINFO_EXTENSION)),['pdf','zip','txt','csv','doc','docx','xls','xlsx','ppt','pptx','jpg','jpeg','png','webp','mp3','mp4','stl','bin','hex'],true)){return new WP_Error('media','Bestandstype niet toegestaan: '.basename($source));}
        if(!filesize($source)||filesize($source)>wp_max_upload_size()){return new WP_Error('media','Bestand is leeg of te groot: '.basename($source));}return $source;
    }
    public function admin() {
        if(!current_user_can('manage_options')){return;}
        echo '<div class="wrap"><h1>Export & import</h1><p>Export bevat beveiligde bestanden, categorieën, toegangsrollen, versies en vormgeving. Gebruikers, favorieten en het downloadlogboek worden niet meegenomen.</p>';
        if(!class_exists('ZipArchive')){echo '<div class="notice notice-error"><p>De PHP-extensie ZIP is nodig voor export en import.</p></div></div>';return;}
        if(isset($_GET['imported'])){echo '<div class="notice notice-success"><p>'.absint($_GET['imported']).' downloads geïmporteerd; '.absint($_GET['skipped']??0).' bestaande downloads overgeslagen.</p></div>';}
        echo '<h2>Exporteren</h2><form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';wp_nonce_field('rex_export');echo '<input type="hidden" name="action" value="rex_export">';submit_button('Download export-ZIP');echo '</form><h2>Importeren</h2><form method="post" enctype="multipart/form-data" action="'.esc_url(admin_url('admin-post.php')).'">';wp_nonce_field('rex_import');echo '<input type="hidden" name="action" value="rex_import"><p><input type="file" name="archive" accept=".zip" required></p><p><label><input type="checkbox" name="import_settings" value="1"> Vormgeving en instellingen uit de export overnemen</label></p><p>Bestaande downloads met dezelfde export-ID worden overgeslagen. Nieuwe downloadrollen krijgen uitsluitend leestoegang. Rollen die hier ontbreken worden niet automatisch naar andere rollen vertaald. Controleer toegang na import. Maximaal 500 bestanden en 512 MB uitgepakt; uploadlimiet '.esc_html(size_format(wp_max_upload_size())).'.</p>';submit_button('Export importeren');echo '</form></div>';
    }
    public function export() {
        $this->guard('rex_export');if(!class_exists('ZipArchive')){wp_die('PHP ZIP-extensie ontbreekt.');}
        $dir=$this->core->storage();if(is_wp_error($dir)){wp_die(esc_html($dir->get_error_message()));}
        $path=$dir.'/export-'.bin2hex(random_bytes(12)).'.zip';
        register_shutdown_function(function()use($path){if(is_file($path)){@unlink($path);}});
        $zip=new ZipArchive();if($zip->open($path,ZipArchive::CREATE|ZipArchive::EXCL)!==true){wp_die('Export kan niet worden gemaakt.');}
        $manifest=['format'=>'wp-dares-downloads','schema'=>1,'plugin_version'=>'3.1.1','categories'=>[],'downloads'=>[],'settings'=>Rex_Downloads_Features::settings(),'roles'=>get_option('rex_download_custom_roles',[])];
        $order=get_option('rex_download_category_order',[]);$terms=get_terms(['taxonomy'=>'rex_download_category','hide_empty'=>false]);
        if(is_wp_error($terms)){$zip->close();@unlink($path);wp_die('Categorieën niet beschikbaar.');}
        foreach($terms as $term){$manifest['categories'][]=['id'=>(int)$term->term_id,'parent'=>(int)$term->parent,'name'=>$term->name,'order'=>(int)($order[$term->term_id]??2147483647),'access_mode'=>get_term_meta($term->term_id,'_rex_access_mode',true),'roles'=>get_term_meta($term->term_id,'_rex_roles',true)];}
        $posts=get_posts(['post_type'=>'rex_download','numberposts'=>-1,'post_status'=>'publish']);
        if(count($posts)>500){$zip->close();@unlink($path);wp_die('Maximaal 500 bestanden per export.');}
        $total=0;
        foreach($posts as $post){
            $file=$this->core->file($post->ID);if(!$file){$zip->close();@unlink($path);wp_die('Bestand ontbreekt: '.esc_html($post->post_title));}
            $total+=filesize($file);if($total>536870912){$zip->close();@unlink($path);wp_die('Export groter dan 512 MB.');}
            $uuid=get_post_meta($post->ID,'_rex_uuid',true);if(!$uuid){$uuid=wp_generate_uuid4();update_post_meta($post->ID,'_rex_uuid',$uuid);}
            $entry='files/'.bin2hex(random_bytes(24)).'.bin';$zip->addFile($file,$entry);
            $meta=[];foreach(['name','roles','access_mode','version','changelog','history','created_at','updated_at','publish_at','expire_at'] as $key){$meta[$key]=get_post_meta($post->ID,'_rex_'.$key,true);}
            foreach(['publish_at','expire_at'] as $field){$meta[$field]=(int)$meta[$field];}
            $effective=[];if(($meta['access_mode']??'')==='inherit'){foreach((array)wp_get_object_terms($post->ID,'rex_download_category',['fields'=>'ids']) as $category){$effective=array_merge($effective,Rex_Downloads_Features::effective_category_roles((int)$category));}}
            $manifest['downloads'][]=['effective_roles'=>array_values(array_unique($effective)),'uuid'=>$uuid,'title'=>$post->post_title,'description'=>$post->post_content,'categories'=>wp_get_object_terms($post->ID,'rex_download_category',['fields'=>'ids']),'archives'=>$this->export_archives($post->ID,$zip,$dir,$total),'meta'=>$meta,'entry'=>$entry,'size'=>filesize($file),'sha256'=>hash_file('sha256',$file)];
        }
        $valid=self::validate_manifest($manifest);if(is_wp_error($valid)){$zip->close();wp_die(esc_html($valid->get_error_message()));}
        $zip->addFromString('manifest.json',wp_json_encode($manifest,JSON_UNESCAPED_UNICODE));
        if(!$zip->close()){@unlink($path);wp_die('Export opslaan mislukt.');}@chmod($path,0600);
        while(ob_get_level()){ob_end_clean();}nocache_headers();header('Content-Type: application/zip');header('X-Content-Type-Options: nosniff');header('Content-Disposition: attachment; filename="wp-dares-downloads-export-'.gmdate('Ymd-His').'.zip"');header('Content-Length: '.filesize($path));readfile($path);unlink($path);exit;
    }
    private function export_archives($id,$zip,$dir,&$total) {
        $out=[];$versions=get_post_meta($id,'_rex_file_versions',true);
        foreach(is_array($versions)?$versions:[] as $version){$key=$version['key']??'';$path=preg_match('/^[a-f0-9]{48}\.bin$/D',$key)?realpath($dir.'/'.$key):false;if(!$path||dirname($path)!==$dir||!is_file($path)){wp_die('Oude bestandsversie ontbreekt.');}$total+=filesize($path);if($total>536870912){wp_die('Export groter dan 512 MB.');}$entry='files/'.bin2hex(random_bytes(24)).'.bin';$zip->addFile($path,$entry);unset($version['key']);$version['entry']=$entry;$version['size']=filesize($path);$version['sha256']=hash_file('sha256',$path);$out[]=$version;}return $out;
    }
    public static function validate_manifest($m) {
        if(!is_array($m)||($m['format']??'')!=='wp-dares-downloads'||($m['schema']??0)!==1||!isset($m['downloads'],$m['categories'])||!is_array($m['downloads'])||!is_array($m['categories'])||count($m['downloads'])>500||count($m['categories'])>5000){return new WP_Error('manifest','Ongeldige export.');}
        $categories=[];
        foreach($m['categories'] as $c){if(!is_array($c)||empty($c['id'])||!is_numeric($c['id'])||(int)$c['id']<1||isset($categories[(int)$c['id']])||!isset($c['name'],$c['parent'])||!is_string($c['name'])||!trim($c['name'])||!is_numeric($c['parent'])||(int)$c['parent']<0){return new WP_Error('manifest','Ongeldige categorie.');}$categories[(int)$c['id']]=(int)$c['parent'];}
        foreach($categories as $id=>$parent){$seen=[$id=>true];while($parent){if(!isset($categories[$parent])||isset($seen[$parent])){return new WP_Error('manifest','Cyclische of ontbrekende hoofdcategorie.');}$seen[$parent]=true;$parent=$categories[$parent];}}
        $total=0;$uuids=[];$entries=[];
        foreach($m['downloads'] as $d){
            if(!is_array($d)||!is_string($d['uuid']??null)||!preg_match('/^[a-f0-9-]{36}$/D',$d['uuid'])||isset($uuids[$d['uuid']])||!is_string($d['entry']??null)||!preg_match('/^files\/[a-f0-9]{48}\.bin$/D',$d['entry'])||isset($entries[$d['entry']])||!is_string($d['sha256']??null)||!preg_match('/^[a-f0-9]{64}$/D',$d['sha256'])||!is_numeric($d['size']??null)||$d['size']<1||!is_string($d['title']??null)||!trim($d['title'])||!is_string($d['description']??null)||!is_array($d['meta']??null)||!is_string($d['meta']['name']??null)||!is_array($d['categories']??null)){return new WP_Error('manifest','Ongeldige bestandsgegevens.');}
            if(!in_array(strtolower(pathinfo($d['meta']['name'],PATHINFO_EXTENSION)),['pdf','zip','txt','csv','doc','docx','xls','xlsx','ppt','pptx','jpg','jpeg','png','webp','mp3','mp4','stl','bin','hex'],true)){return new WP_Error('manifest','Bestandstype niet toegestaan.');}
            foreach($d['categories'] as $id){if(!isset($categories[(int)$id])){return new WP_Error('manifest','Onbekende bestands-categorie.');}}
            if(!is_array($d['archives']??[])){return new WP_Error('manifest','Ongeldige oude versies.');}foreach($d['archives']??[] as $archive){if(!is_array($archive)||!is_string($archive['entry']??null)||!preg_match('/^files\/[a-f0-9]{48}\.bin$/D',$archive['entry'])||isset($entries[$archive['entry']])||$archive['entry']===$d['entry']||!is_string($archive['sha256']??null)||!preg_match('/^[a-f0-9]{64}$/D',$archive['sha256'])||!is_numeric($archive['size']??null)||$archive['size']<1||!is_string($archive['name']??null)){return new WP_Error('manifest','Ongeldige oude bestandsversie.');}$entries[$archive['entry']]=true;$total+=(int)$archive['size'];}if(count($entries)>5000||!is_array($d['archives']??[])){return new WP_Error('manifest','Te veel of ongeldige oude versies.');}
            $start=$d['meta']['publish_at']??0;$end=$d['meta']['expire_at']??0;if(!is_numeric($start)||!is_numeric($end)||$start<0||$end<0||($start&&$end&&$end<=$start)){return new WP_Error('manifest','Ongeldige publicatieplanning.');}
            $uuids[$d['uuid']]=true;$entries[$d['entry']]=true;$total+=(int)$d['size'];if($total>536870912){return new WP_Error('manifest','Uitgepakte export is groter dan 512 MB.');}
        }
        return true;
    }
    public function import() {
        $this->guard('rex_import');if(!class_exists('ZipArchive')){wp_die('PHP ZIP-extensie ontbreekt.');}
        $upload=$_FILES['archive']??[];if(($upload['error']??1)!==UPLOAD_ERR_OK||!is_uploaded_file($upload['tmp_name']??'')||($upload['size']??0)>wp_max_upload_size()){wp_die('Upload mislukt of te groot.');}
        $zip=new ZipArchive();if($zip->open($upload['tmp_name'])!==true){wp_die('Ongeldig ZIP-bestand.');}
        $stat=$zip->statName('manifest.json');if(!$stat||$stat['size']>5242880){$zip->close();wp_die('Exportmanifest ontbreekt of is te groot.');}
        $m=json_decode($zip->getFromName('manifest.json'),true);$valid=self::validate_manifest($m);if(is_wp_error($valid)){$zip->close();wp_die(esc_html($valid->get_error_message()));}
        $dir=$this->core->storage();if(is_wp_error($dir)){$zip->close();wp_die(esc_html($dir->get_error_message()));}
        $staged=[];
        try{
            foreach($m['downloads'] as $i=>$download){
              foreach(array_merge([$download],$download['archives']??[]) as $version_index=>$d){
                $stage_id=$version_index?$i.':'.$version_index:$i;
                $stat=$zip->statName($d['entry']);if(!$stat||(int)$stat['size']!==(int)$d['size']){throw new RuntimeException('Bestandsgrootte komt niet overeen.');}
                $stream=$zip->getStream($d['entry']);if(!$stream){throw new RuntimeException('Bestand ontbreekt in export.');}
                $key=bin2hex(random_bytes(24)).'.bin';$path=$dir.'/'.$key;$staged[$stage_id]=['key'=>$key,'path'=>$path];
                $out=fopen($path,'xb');if(!$out){fclose($stream);throw new RuntimeException('Privémap niet schrijfbaar.');}
                $written=stream_copy_to_stream($stream,$out,(int)$d['size']+1);fclose($stream);fclose($out);@chmod($path,0600);
                if($written!==(int)$d['size']||!hash_equals($d['sha256'],hash_file('sha256',$path))){throw new RuntimeException('Bestandscontrole mislukt.');}
            }
              }
            $zip->close();
        }catch(Throwable $e){$zip->close();foreach($staged as $stage){@unlink($stage['path']);}wp_die(esc_html($e->getMessage()));}
        $imported=0;$skipped=0;
        try{
            // Only recreate this plugin's read-only roles; never import capabilities.
            $owned=get_option('rex_download_custom_roles',[]);$owned=is_array($owned)?$owned:[];
            foreach((array)($m['roles']??[]) as $key=>$name){if(is_string($key)&&preg_match('/^rex_[a-z0-9_-]+$/D',$key)&&is_string($name)&&!get_role($key)){add_role($key,sanitize_text_field($name),['read'=>true]);$owned[$key]=sanitize_text_field($name);}}
            update_option('rex_download_custom_roles',$owned,false);
            $pending=$m['categories'];$mapping=[];$order=get_option('rex_download_category_order',[]);$order=is_array($order)?$order:[];
            while($pending){$progress=false;foreach($pending as $i=>$c){$parent=(int)$c['parent'];if($parent&&!isset($mapping[$parent])){continue;}$target_parent=$parent?$mapping[$parent]:0;$name=sanitize_text_field($c['name']);$exists=term_exists($name,'rex_download_category',$target_parent);$is_new=!$exists;$result=$exists?:wp_insert_term($name,'rex_download_category',['parent'=>$target_parent]);if(is_wp_error($result)){throw new RuntimeException($result->get_error_message());}$id=is_array($result)?(int)$result['term_id']:(int)$result;$mapping[(int)$c['id']]=$id;if($is_new){update_term_meta($id,'_rex_access_mode',($c['access_mode']??'')==='custom'?'custom':'inherit');update_term_meta($id,'_rex_roles',Rex_Downloads_Features::roles($c['roles']??[]));$order[$id]=max(0,(int)($c['order']??2147483647));}unset($pending[$i]);$progress=true;}if(!$progress){throw new RuntimeException('Categorieboom kan niet worden geïmporteerd.');}}
            update_option('rex_download_category_order',$order,false);
            foreach($m['downloads'] as $i=>$d){
                $existing=get_posts(['post_type'=>'rex_download','post_status'=>'any','numberposts'=>1,'meta_key'=>'_rex_uuid','meta_value'=>$d['uuid']]);
                if($existing){foreach(array_merge([$d],$d['archives']??[]) as $version_index=>$ignored){$stage_id=$version_index?$i.':'.$version_index:$i;@unlink($staged[$stage_id]['path']);unset($staged[$stage_id]);}$skipped++;continue;}
                $id=wp_insert_post(['post_type'=>'rex_download','post_status'=>'publish','post_title'=>sanitize_text_field($d['title']),'post_content'=>sanitize_textarea_field($d['description'])],true);if(is_wp_error($id)){throw new RuntimeException($id->get_error_message());}
                update_post_meta($id,'_rex_key',$staged[$i]['key']);update_post_meta($id,'_rex_size',(int)$d['size']);update_post_meta($id,'_rex_uuid',$d['uuid']);update_post_meta($id,'_rex_name',sanitize_file_name($d['meta']['name']));
                $mode=($d['meta']['access_mode']??'')==='inherit'?'inherit':'explicit';
                $roles=Rex_Downloads_Features::roles($d['meta']['roles']??[]);
                if($mode==='inherit'){
                    $expected=Rex_Downloads_Features::roles($d['effective_roles']??[]);$actual=[];
                    foreach($d['categories'] as $category){$actual=array_merge($actual,Rex_Downloads_Features::effective_category_roles($mapping[(int)$category]));}
                    $actual=array_values(array_unique($actual));sort($actual);sort($expected);
                    if($actual!==$expected){$mode='explicit';$roles=$expected;}
                }
                update_post_meta($id,'_rex_roles',$roles);update_post_meta($id,'_rex_access_mode',$mode);
                foreach(['version','changelog'] as $field){update_post_meta($id,'_rex_'.$field,sanitize_textarea_field(is_string($d['meta'][$field]??null)?$d['meta'][$field]:''));}
                foreach(['created_at','updated_at'] as $field){update_post_meta($id,'_rex_'.$field,max(0,min(time(),(int)($d['meta'][$field]??time()))));}
                $history=[];foreach(array_slice(is_array($d['meta']['history']??null)?$d['meta']['history']:[],-50) as $entry){if(is_array($entry)){$history[]=['time'=>max(0,min(time(),(int)($entry['time']??time()))),'version'=>sanitize_text_field(is_string($entry['version']??null)?$entry['version']:''),'notes'=>sanitize_textarea_field(is_string($entry['notes']??null)?$entry['notes']:'')];}}update_post_meta($id,'_rex_history',$history);
                wp_set_object_terms($id,array_map(function($category)use($mapping){return $mapping[(int)$category];},$d['categories']),'rex_download_category');
                update_post_meta($id,'_rex_sha256',$d['sha256']);foreach(['publish_at','expire_at'] as $field){update_post_meta($id,'_rex_'.$field,max(0,(int)($d['meta'][$field]??0)));}
                $versions=[];foreach($d['archives']??[] as $index=>$archive){$stage_id=$i.':'.($index+1);$versions[]=['key'=>$staged[$stage_id]['key'],'name'=>sanitize_file_name($archive['name']),'size'=>(int)$archive['size'],'sha256'=>$archive['sha256'],'version'=>sanitize_text_field($archive['version']??''),'changelog'=>sanitize_textarea_field($archive['changelog']??''),'time'=>max(0,(int)($archive['time']??0))];unset($staged[$stage_id]);}update_post_meta($id,'_rex_file_versions',$versions);
                unset($staged[$i]);$imported++;
            }
            if(!empty($_POST['import_settings'])&&is_array($m['settings']??null)){update_option('rex_download_settings',Rex_Downloads_Features::clean_settings($m['settings']),false);}
        }catch(Throwable $e){foreach($staged as $stage){@unlink($stage['path']);}wp_die(esc_html('Import gestopt na '.$imported.' bestanden: '.$e->getMessage().' Reeds geïmporteerde bestanden blijven behouden. Opnieuw importeren slaat deze over.'));}
        wp_safe_redirect(admin_url('admin.php?page=rex-download-migration&imported='.$imported.'&skipped='.$skipped));exit;
    }
}
