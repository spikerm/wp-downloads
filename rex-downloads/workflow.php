<?php
if (!defined('ABSPATH')) { exit; }
final class Rex_Downloads_Workflow {
    private $core;
    private $changes=[];
    public function __construct($core) {
        $this->core=$core;
        add_action('init',function(){register_post_type('rex_access_request',['public'=>false,'show_ui'=>false,'supports'=>['title','author']]);register_post_status('rex_trash',['label'=>'Downloadprullenbak','public'=>false,'internal'=>false,'exclude_from_search'=>true]);});
        add_action('admin_menu',[$this,'menus'],26);
        add_action('admin_enqueue_scripts',[$this,'assets']);
        add_action('rex_download_replacing',[$this,'archive_current'],10,2);
        add_action('rex_download_workflow_fields',[$this,'fields']);
        add_action('rex_download_saved',[$this,'capture_change'],5,3);
        add_action('rex_download_saved',[$this,'saved'],20,3);
        add_action('rex_download_subscriptions',[$this,'subscriptions']);
        add_action('rex_bulk_manager',function(){echo '<p><a class="button" href="'.esc_url(admin_url('admin.php?page=rex-download-manager')).'">Bulkbeheer & bestandsvolgorde</a></p>';});
        foreach(['bulk_files','file_order','trash_action','restore_version','version_download','decide_request'] as $action){add_action('admin_post_rex_'.$action,[$this,$action]);}
        foreach(['request_access','watch','integrity_begin','integrity_batch'] as $action){add_action('wp_ajax_rex_'.$action,[$this,$action]);}
        add_action('rex_download_notify',[$this,'notify'],10,4);
    }
    public function menus() {
        foreach(['dashboard'=>'Dashboard','manager'=>'Bestanden beheren','trash'=>'Prullenbak','requests'=>'Toegangsaanvragen','integrity'=>'Bestandscontrole'] as $method=>$label){add_submenu_page('rex-downloads',$label,$label,'manage_options','rex-download-'.$method,[$this,$method]);}
    }
    public function assets() {
        if(strpos(sanitize_key($_GET['page']??''),'rex-download-')===0){wp_enqueue_script('rex-workflow-admin',plugins_url('workflow-admin.js',__FILE__),['jquery','jquery-ui-sortable'],'3.1.0',true);}
    }
    private function guard($nonce='rex_workflow_admin') {if(!current_user_can('manage_options')){wp_die('Geen toegang.','',['response'=>403]);}check_admin_referer($nonce);}
    private function ajax_guard($admin=false){if(!is_user_logged_in()||($admin&&!current_user_can('manage_options'))){wp_send_json_error(['message'=>'Geen toegang.'],403);}check_ajax_referer('rex_workflow','nonce');}
    private function back($page){wp_safe_redirect(admin_url('admin.php?page=rex-download-'.$page));exit;}
    public static function available($id,$now=null) {
        if(get_post_type($id)!=='rex_download'||get_post_status($id)!=='publish'){return false;}
        $now=$now??time();$start=(int)get_post_meta($id,'_rex_publish_at',true);$end=(int)get_post_meta($id,'_rex_expire_at',true);
        return (!$start||$start<=$now)&&(!$end||$now<$end);
    }
    public static function grants($user_id) {$ids=get_user_meta($user_id,'rex_download_grants',true);return is_array($ids)?array_values(array_unique(array_map('absint',$ids))):[];}
    public static function allowed_for($id,$user) {
        if(!$user||empty($user->ID)||!self::available($id)){return false;}
        if(user_can($user,'manage_options')||in_array((int)$id,self::grants($user->ID),true)){return true;}
        if(get_post_meta($id,'_rex_access_mode',true)==='inherit'){return Rex_Downloads_Features::category_access($id,$user);}
        $roles=(array)get_post_meta($id,'_rex_roles',true);return in_array('@guest',$roles,true)||in_array('*',$roles,true)||(bool)array_intersect($roles,(array)$user->roles);
    }
    public static function watches($user_id=null){$value=get_user_meta($user_id??get_current_user_id(),'rex_download_watches',true);return is_array($value)?array_values(array_filter(array_unique($value),'is_string')):[];}
    public static function ordered_files($posts,$category=0) {
        $orders=get_option('rex_download_file_order',[]);$orders=is_array($orders)?$orders:[];$order=$orders[$category]??($orders[0]??[]);$order=is_array($order)?$order:[];
        usort($posts,function($a,$b)use($order){return (($order[$a->ID]??PHP_INT_MAX)<=>($order[$b->ID]??PHP_INT_MAX))?:strnatcasecmp($a->post_title,$b->post_title);});return $posts;
    }
    public static function trash_file($id) {
        $post=get_post($id);if(!$post||$post->post_type!=='rex_download'||$post->post_status==='rex_trash'){return false;}
        update_post_meta($id,'_rex_pre_trash_status',$post->post_status);
        $result=wp_update_post(['ID'=>$id,'post_status'=>'rex_trash'],true);return !is_wp_error($result)&&$result;
    }
    private function path($key) {
        if(!is_string($key)||!preg_match('/^[a-f0-9]{48}\.bin$/D',$key)){return false;}$dir=$this->core->storage();if(is_wp_error($dir)){return false;}$path=realpath($dir.'/'.$key);return $path&&dirname($path)===$dir&&is_file($path)?$path:false;
    }
    public function archive_current($id,$path) {
        if(!$path||!is_file($path)){return;}
        $key=get_post_meta($id,'_rex_key',true);$versions=get_post_meta($id,'_rex_file_versions',true);$versions=is_array($versions)?$versions:[];
        foreach($versions as $version){if(($version['key']??'')===$key){return;}}
        $entry=['key'=>$key,'time'=>time(),'sha256'=>hash_file('sha256',$path)];foreach(['name','size','version','changelog'] as $field){$entry[$field]=get_post_meta($id,'_rex_'.$field,true);}
        $versions[]=$entry;update_post_meta($id,'_rex_file_versions',$versions);
    }
    private static function date_input($timestamp){return $timestamp?wp_date('Y-m-d\TH:i',(int)$timestamp):'';}
    public static function parse_date($value){if(!$value){return 0;}if(!is_string($value)||!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/D',$value)){return new WP_Error('date','Ongeldige datum.');}$date=DateTimeImmutable::createFromFormat('!Y-m-d\TH:i',$value,wp_timezone());if(!$date||$date->format('Y-m-d\TH:i')!==$value){return new WP_Error('date','Ongeldige datum.');}return $date->getTimestamp();}
    public function fields($id) {
        echo '</td></tr><tr><th>Publicatie plannen</th><td><label>Beschikbaar vanaf <input type="datetime-local" name="publish_at" value="'.esc_attr(self::date_input($id?get_post_meta($id,'_rex_publish_at',true):0)).'"></label><p><label>Beschikbaar tot <input type="datetime-local" name="expire_at" value="'.esc_attr(self::date_input($id?get_post_meta($id,'_rex_expire_at',true):0)).'"></label></p><p>Tijden volgen de WordPress-tijdzone. Leeg: direct beschikbaar, zonder einddatum. De toegang stopt op de einddatum, ook via directe links.</p><p><label><input type="checkbox" name="notify_subscribers" value="1" checked> Abonnees informeren bij een nieuw of vervangen bestand / gewijzigde versie</label></p>';
        if(!$id){return;}$versions=get_post_meta($id,'_rex_file_versions',true);if(!is_array($versions)||!$versions){return;}
        echo '<details><summary>Oude bestandsversies ('.count($versions).')</summary><p>Oude bestanden blijven behouden totdat de download definitief uit de prullenbak wordt verwijderd. Terugzetten bewaart ook de huidige versie.</p><ul>';
        foreach(array_reverse($versions) as $version){$key=$version['key'];$download=wp_nonce_url(add_query_arg(['action'=>'rex_version_download','id'=>$id,'key'=>$key],admin_url('admin-post.php')),'rex_version_'.$id);$restore=wp_nonce_url(add_query_arg(['action'=>'rex_restore_version','id'=>$id,'key'=>$key],admin_url('admin-post.php')),'rex_restore_'.$id);echo '<li>'.esc_html(($version['version']?:'Zonder versie').' — '.$version['name'].' — '.wp_date('d-m-Y H:i',$version['time'])).' <a href="'.esc_url($download).'">Download</a> · <a href="'.esc_url($restore).'" onclick="return confirm(\'Deze versie terugzetten? De huidige versie wordt bewaard.\')">Terugzetten</a></li>';}
        echo '</ul></details>';
    }
    public static function validate_schedule() {
        $start=self::parse_date(wp_unslash($_POST['publish_at']??''));$end=self::parse_date(wp_unslash($_POST['expire_at']??''));
        if(is_wp_error($start)||is_wp_error($end)||($start&&$end&&$end<=$start)){wp_die('Controleer de publicatiedatums. De einddatum moet na de begindatum liggen.');}return [$start,$end];
    }
    public function capture_change($id,$replaced,$existing) {$this->changes[$id]=!$existing||$replaced||sanitize_text_field(wp_unslash($_POST['version']??''))!==get_post_meta($id,'_rex_version',true);}
    public function saved($id,$replaced,$existing) {
        $previous_start=(int)get_post_meta($id,'_rex_publish_at',true);list($start,$end)=$this->validate_schedule();update_post_meta($id,'_rex_publish_at',$start);update_post_meta($id,'_rex_expire_at',$end);
        $path=$this->core->file($id);if($path&&($replaced||!get_post_meta($id,'_rex_sha256',true))){update_post_meta($id,'_rex_sha256',hash_file('sha256',$path));}
        $changed=$this->changes[$id]??(!$existing||$replaced);
        if(!$changed&&$start!==$previous_start&&get_post_meta($id,'_rex_notify_token',true)){wp_schedule_single_event(max(time()+60,$start),'rex_download_notify',[$id,get_post_meta($id,'_rex_notify_token',true),0,1]);}
        if($changed&&!empty($_POST['notify_subscribers'])){$token=wp_generate_uuid4();update_post_meta($id,'_rex_notify_token',$token);wp_schedule_single_event(max(time()+60,$start),'rex_download_notify',[$id,$token,0,1]);}
    }
    public function restore_version() {
        $id=absint($_REQUEST['id']??0);$this->guard('rex_restore_'.$id);if(get_post_type($id)!=='rex_download'){wp_die('Ongeldig bestand.');}
        $key=sanitize_text_field(wp_unslash($_REQUEST['key']??''));$versions=get_post_meta($id,'_rex_file_versions',true);$versions=is_array($versions)?$versions:[];$selected=null;
        foreach($versions as $version){if(($version['key']??'')===$key){$selected=$version;break;}}
        $path=$selected?$this->path($key):false;if(!$path||(!empty($selected['sha256'])&&!hash_equals($selected['sha256'],hash_file('sha256',$path)))){wp_die('Oude bestandsversie ontbreekt of is beschadigd.');}
        $this->archive_current($id,$this->core->file($id));$versions=get_post_meta($id,'_rex_file_versions',true);$versions=array_values(array_filter($versions,function($entry)use($key){return $entry['key']!==$key;}));update_post_meta($id,'_rex_file_versions',$versions);
        foreach(['key','name','size','version','changelog','sha256'] as $field){update_post_meta($id,'_rex_'.$field,$selected[$field]??'');}update_post_meta($id,'_rex_updated_at',time());
        $history=get_post_meta($id,'_rex_history',true);$history=is_array($history)?$history:[];$history[]=['time'=>time(),'version'=>$selected['version']??'','notes'=>'Vorige bestandsversie teruggezet.'];update_post_meta($id,'_rex_history',array_slice($history,-50));
        wp_safe_redirect(admin_url('admin.php?page=rex-downloads&edit='.$id));exit;
    }
    public function version_download() {
        $id=absint($_GET['id']??0);$this->guard('rex_version_'.$id);if(get_post_type($id)!=='rex_download'){wp_die('Ongeldig bestand.');}$key=sanitize_text_field(wp_unslash($_GET['key']??''));$versions=get_post_meta($id,'_rex_file_versions',true);$selected=null;
        foreach(is_array($versions)?$versions:[] as $version){if($version['key']===$key){$selected=$version;break;}}$path=$selected?$this->path($key):false;if(!$path){wp_die('Versie niet beschikbaar.','',['response'=>404]);}
        while(ob_get_level()){ob_end_clean();}nocache_headers();header('Content-Type: application/octet-stream');header('X-Content-Type-Options: nosniff');header('Content-Disposition: attachment; filename="version.bin"; filename*=UTF-8\'\''.rawurlencode($selected['name']));header('Content-Length: '.filesize($path));readfile($path);exit;
    }
    public function manager() {
        if(!current_user_can('manage_options')){return;}$category=absint($_GET['category']??0);$posts=get_posts(['post_type'=>'rex_download','post_status'=>'publish','numberposts'=>-1]);
        if($category){$posts=array_values(array_filter($posts,function($post)use($category){$terms=wp_get_object_terms($post->ID,'rex_download_category',['fields'=>'ids']);return !is_wp_error($terms)&&in_array($category,array_map('intval',$terms),true);}));}$posts=self::ordered_files($posts,$category);
        echo '<div class="wrap"><h1>Bestanden beheren</h1><form method="get"><input type="hidden" name="page" value="rex-download-manager"><label>Volgorde voor <select name="category"><option value="0">Alle bestanden / standaardvolgorde</option>';foreach($this->core->category_tree() as $row){echo '<option value="'.(int)$row['term']->term_id.'" '.selected($category,$row['term']->term_id,false).'>'.esc_html(str_repeat('— ',$row['depth']).$row['term']->name).'</option>';}echo '</select></label> <button class="button">Tonen</button></form><p>Versleep bestanden met ↕ en sla de volgorde op. De gekozen categorie krijgt een eigen volgorde. Vink bestanden aan voor een bulkactie.</p><form method="post" action="'.esc_url(admin_url('admin-post.php')).'" id="rex-file-order-form">';wp_nonce_field('rex_workflow_admin');echo '<input type="hidden" name="action" value="rex_file_order"><input type="hidden" name="category" value="'.$category.'"><input type="hidden" name="order" id="rex-file-order"><table class="widefat striped"><thead><tr><th><input type="checkbox" id="rex-select-all" aria-label="Alle bestanden selecteren"></th><th>Volgorde</th><th>Bestand</th><th>Versie</th><th>Publicatie</th></tr></thead><tbody id="rex-file-sort">';
        foreach($posts as $post){$id=$post->ID;echo '<tr data-file-id="'.(int)$id.'"><td><input type="checkbox" class="rex-bulk-checkbox" value="'.(int)$id.'" aria-label="'.esc_attr($post->post_title.' selecteren').'"></td><td><span class="rex-file-handle" style="cursor:grab">↕</span> <button type="button" class="button rex-file-up">↑</button> <button type="button" class="button rex-file-down">↓</button></td><td><a href="'.esc_url(admin_url('admin.php?page=rex-downloads&edit='.$id)).'">'.esc_html($post->post_title).'</a></td><td>'.esc_html(get_post_meta($id,'_rex_version',true)).'</td><td>'.(self::available($id)?'Beschikbaar':'Gepland / verlopen').'</td></tr>';}
        echo '</tbody></table>';submit_button('Bestandsvolgorde opslaan');echo '</form><h2>Bulkactie op geselecteerde bestanden</h2><form method="post" action="'.esc_url(admin_url('admin-post.php')).'" id="rex-bulk-files-form">';wp_nonce_field('rex_workflow_admin');echo '<input type="hidden" name="action" value="rex_bulk_files"><input type="hidden" name="ids" id="rex-bulk-ids"><select name="bulk_action" required><option value="">Kies een actie</option><option value="category">Categorieën vervangen</option><option value="roles">Toegangsrollen vervangen</option><option value="schedule">Publicatieplanning vervangen</option><option value="trash">Naar prullenbak</option></select><h3>Categorieën voor verplaatsen</h3>';
        foreach($this->core->category_tree() as $row){echo '<label style="display:block"><input type="checkbox" name="categories[]" value="'.(int)$row['term']->term_id.'"> '.esc_html(str_repeat('— ',$row['depth']).$row['term']->name).'</label>';}
        echo '<h3>Rollen voor toegangsaanpassing</h3>';foreach(array_merge(['*'=>'Alle ingelogde gebruikers','@guest'=>'Gasten (openbaar, ook voor ingelogde gebruikers)'],wp_roles()->get_names()) as $key=>$label){echo '<label style="display:block"><input type="checkbox" name="roles[]" value="'.esc_attr($key).'"> '.esc_html(translate_user_role($label)).'</label>';}
        echo '<h3>Publicatieplanning</h3><label>Vanaf <input type="datetime-local" name="publish_at"></label> <label>Tot <input type="datetime-local" name="expire_at"></label><p>De gekozen actie vervangt alleen dat onderdeel. Geen categorieën: categorieën verwijderen. Geen rollen: alleen beheerders.</p>';submit_button('Bulkactie uitvoeren');echo '<p id="rex-bulk-status" role="status"></p></form></div>';
    }
    public function file_order() {
        $this->guard();$category=absint($_POST['category']??0);$ids=json_decode(wp_unslash($_POST['order']??''),true);if(!is_array($ids)||count($ids)>5000){wp_die('Ongeldige bestandsvolgorde.');}$ids=array_map('absint',$ids);if(count(array_unique($ids))!==count($ids)){wp_die('Dubbele bestanden.');}
        foreach($ids as $id){if(get_post_type($id)!=='rex_download'||get_post_status($id)!=='publish'){wp_die('Ongeldig bestand.');}if($category){$terms=wp_get_object_terms($id,'rex_download_category',['fields'=>'ids']);if(is_wp_error($terms)||!in_array($category,array_map('intval',$terms),true)){wp_die('Bestand hoort niet bij deze categorie.');}}}
        $orders=get_option('rex_download_file_order',[]);$orders=is_array($orders)?$orders:[];$orders[$category]=array_flip($ids);update_option('rex_download_file_order',$orders,false);wp_safe_redirect(admin_url('admin.php?page=rex-download-manager&category='.$category));exit;
    }
    public function bulk_files() {
        $this->guard();$ids=json_decode(wp_unslash($_POST['ids']??''),true);$action=sanitize_key($_POST['bulk_action']??'');if(!is_array($ids)||!$ids||count($ids)>500||!in_array($action,['category','roles','schedule','trash'],true)){wp_die('Selecteer 1 tot 500 bestanden en een geldige actie.');}$ids=array_values(array_unique(array_map('absint',$ids)));foreach($ids as $id){if(get_post_type($id)!=='rex_download'||get_post_status($id)!=='publish'){wp_die('Ongeldig bestand in selectie.');}}
        $categories=array_values(array_unique(array_map('absint',(array)($_POST['categories']??[]))));foreach($categories as $id){if(!term_exists($id,'rex_download_category')){wp_die('Ongeldige categorie.');}}
        $roles=Rex_Downloads_Features::roles(wp_unslash($_POST['roles']??[]));$schedule=$action==='schedule'?$this->validate_schedule():[0,0];
        foreach($ids as $id){if($action==='trash'){self::trash_file($id);}elseif($action==='category'){wp_set_object_terms($id,$categories,'rex_download_category');}elseif($action==='roles'){update_post_meta($id,'_rex_roles',$roles);update_post_meta($id,'_rex_access_mode','explicit');}else{update_post_meta($id,'_rex_publish_at',$schedule[0]);update_post_meta($id,'_rex_expire_at',$schedule[1]);$token=get_post_meta($id,'_rex_notify_token',true);if($token){wp_schedule_single_event(max(time()+60,$schedule[0]),'rex_download_notify',[$id,$token,0,1]);}}}
        $this->back('manager');
    }
    public function trash() {
        if(!current_user_can('manage_options')){return;}echo '<div class="wrap"><h1>Prullenbak</h1><p>Bestanden en oude versies blijven hier bewaard. Ze zijn niet beschikbaar voor gebruikers. Definitief verwijderen wist ook alle opgeslagen bestandsversies.</p><table class="widefat striped"><thead><tr><th>Bestand</th><th>Acties</th></tr></thead><tbody>';
        foreach(get_posts(['post_type'=>'rex_download','post_status'=>'rex_trash','numberposts'=>-1]) as $post){echo '<tr><td>'.esc_html($post->post_title).'</td><td>';foreach(['restore'=>'Herstellen','purge'=>'Definitief verwijderen'] as $action=>$label){echo '<form style="display:inline-block;margin-right:12px" method="post" action="'.esc_url(admin_url('admin-post.php')).'" '.($action==='purge'?'onsubmit="return confirm(\'Bestand en alle oude versies definitief verwijderen?\')"':'').'>';wp_nonce_field('rex_workflow_admin');echo '<input type="hidden" name="action" value="rex_trash_action"><input type="hidden" name="id" value="'.(int)$post->ID.'"><input type="hidden" name="operation" value="'.esc_attr($action).'"><button class="button">'.esc_html($label).'</button></form>';}echo '</td></tr>';}
        echo '</tbody></table></div>';
    }
    public function trash_action() {
        $this->guard();$id=absint($_POST['id']??0);$action=sanitize_key($_POST['operation']??'');if(get_post_type($id)!=='rex_download'||get_post_status($id)!=='rex_trash'){wp_die('Bestand staat niet in de prullenbak.');}
        if($action==='restore'){$status=get_post_meta($id,'_rex_pre_trash_status',true);$result=wp_update_post(['ID'=>$id,'post_status'=>in_array($status,['publish','draft','private'],true)?$status:'publish'],true);if(is_wp_error($result)){wp_die('Herstellen mislukt.');}}
        elseif($action==='purge'){$versions=get_post_meta($id,'_rex_file_versions',true);$keys=[get_post_meta($id,'_rex_key',true)];foreach(is_array($versions)?$versions:[] as $entry){$keys[]=$entry['key']??'';}foreach(array_unique($keys) as $key){$path=$this->path($key);if($path&&!unlink($path)){wp_die('Niet alle bestanden konden worden verwijderd.');}}wp_delete_post($id,true);}
        else{wp_die('Ongeldige actie.');}$this->back('trash');
    }
    public static function category_actions($id) {
        if(!is_user_logged_in()){return;}$watched=in_array('category:'.$id,self::watches(),true);
        echo '<div class="rex-category-actions"><button type="button" class="rex-watch" data-kind="category" data-id="'.(int)$id.'" aria-pressed="'.($watched?'true':'false').'">'.($watched?'Categorie niet meer volgen':'Categorie volgen').'</button> <button type="button" class="rex-request" data-id="'.(int)$id.'">Toegang aanvragen</button><span class="rex-workflow-status" role="status"></span></div>';
    }
    public static function file_actions($id) {
        if(!is_user_logged_in()){return;}$watched=in_array('file:'.$id,self::watches(),true);echo '<button type="button" class="rex-watch" data-kind="file" data-id="'.(int)$id.'" aria-pressed="'.($watched?'true':'false').'">'.($watched?'Niet meer volgen':'Volgen').'</button><span class="rex-workflow-status" role="status"></span>';
    }
    public function subscriptions() {
        $watches=self::watches();if(!$watches){return;}echo '<details class="rex-subscriptions"><summary>Mijn abonnementen ('.count($watches).')</summary><ul>';
        foreach($watches as $key){$parts=explode(':',$key);if(count($parts)!==2||!in_array($parts[0],['file','category'],true)){continue;}$id=absint($parts[1]);$label='Niet beschikbare download';if($parts[0]==='file'&&$this->core->allowed($id)){$label=get_post($id)->post_title;}elseif($parts[0]==='category'){$term=get_term($id,'rex_download_category');$label=$term&&!is_wp_error($term)?$term->name:'Verwijderde categorie';}echo '<li>'.esc_html($label).' <button type="button" class="rex-watch" data-kind="'.esc_attr($parts[0]).'" data-id="'.$id.'" aria-pressed="true">Niet meer volgen</button><span class="rex-workflow-status" role="status"></span></li>';}echo '</ul></details>';
    }
    public function watch() {
        $this->ajax_guard();$id=absint($_POST['id']??0);$kind=sanitize_key($_POST['kind']??'');$active=($_POST['active']??'')==='1';
        $existing=in_array($kind.':'.$id,self::watches(),true);if(!in_array($kind,['file','category'],true)||($active&&(($kind==='file'&&!$this->core->allowed($id))||($kind==='category'&&!term_exists($id,'rex_download_category'))))||(!$active&&!$existing)){wp_send_json_error(['message'=>'Onderdeel niet beschikbaar.'],403);}
        $key=$kind.':'.$id;$values=self::watches();$active=($_POST['active']??'')==='1';$values=array_values(array_diff($values,[$key]));if($active){if(count($values)>=1000){wp_send_json_error(['message'=>'Maximaal 1000 abonnementen.'],400);}$values[]=$key;}update_user_meta(get_current_user_id(),'rex_download_watches',$values);wp_send_json_success(['active'=>$active,'message'=>$active?'Je ontvangt updates per e-mail.':'Abonnement beëindigd.']);
    }
    public function request_access() {
        $this->ajax_guard();$category=absint($_POST['id']??0);$term=get_term($category,'rex_download_category');if(!$term||is_wp_error($term)){wp_send_json_error(['message'=>'Categorie bestaat niet.'],400);}
        $requests=get_posts(['post_type'=>'rex_access_request','post_status'=>'publish','author'=>get_current_user_id(),'numberposts'=>-1]);foreach($requests as $request){if((int)get_post_meta($request->ID,'_rex_category',true)===$category&&get_post_meta($request->ID,'_rex_request_state',true)==='pending'){wp_send_json_success(['message'=>'Je aanvraag staat al klaar voor beoordeling.']);}}
        if(count($requests)>=1000){wp_send_json_error(['message'=>'Te veel aanvragen. Neem contact op met de beheerder.'],400);}
        $id=wp_insert_post(['post_type'=>'rex_access_request','post_status'=>'publish','post_author'=>get_current_user_id(),'post_title'=>'Toegang: '.$term->name],true);if(is_wp_error($id)){wp_send_json_error(['message'=>'Aanvraag opslaan mislukt.'],500);}update_post_meta($id,'_rex_category',$category);update_post_meta($id,'_rex_request_state','pending');wp_send_json_success(['message'=>'Je aanvraag is ingediend bij de beheerder.']);
    }
    public function requests() {
        if(!current_user_can('manage_options')){return;}echo '<div class="wrap"><h1>Toegangsaanvragen</h1><p>Goedkeuren geeft deze gebruiker toegang tot de huidige bestanden in de categorie en haar subcategorieën. Rollen veranderen niet. Publicatiedatums blijven gelden.</p><table class="widefat striped"><thead><tr><th>Gebruiker</th><th>Categorie</th><th>Status</th><th>Acties</th></tr></thead><tbody>';
        foreach(get_posts(['post_type'=>'rex_access_request','post_status'=>'publish','numberposts'=>-1]) as $request){$user=get_userdata($request->post_author);$term=get_term((int)get_post_meta($request->ID,'_rex_category',true),'rex_download_category');$state=get_post_meta($request->ID,'_rex_request_state',true);echo '<tr><td>'.esc_html($user?$user->display_name.' ('.$user->user_email.')':'Verwijderde gebruiker').'</td><td>'.esc_html($term&&!is_wp_error($term)?$term->name:'Verwijderde categorie').'</td><td>'.esc_html($state).'</td><td>';foreach(($state==='approved'?['revoke'=>'Intrekken']:['approve'=>'Goedkeuren','deny'=>'Afwijzen']) as $operation=>$label){echo '<form style="display:inline-block;margin-right:8px" method="post" action="'.esc_url(admin_url('admin-post.php')).'">';wp_nonce_field('rex_workflow_admin');echo '<input type="hidden" name="action" value="rex_decide_request"><input type="hidden" name="id" value="'.(int)$request->ID.'"><input type="hidden" name="operation" value="'.$operation.'"><button class="button">'.$label.'</button></form>';}echo '</td></tr>';}echo '</tbody></table></div>';
    }
    public function decide_request() {
        $this->guard();$id=absint($_POST['id']??0);$operation=sanitize_key($_POST['operation']??'');$request=get_post($id);if(!$request||$request->post_type!=='rex_access_request'||!in_array($operation,['approve','deny','revoke'],true)){wp_die('Ongeldige aanvraag.');}$user=get_userdata($request->post_author);if(!$user){wp_die('Gebruiker bestaat niet meer.');}$grants=self::grants($user->ID);
        if($operation==='approve'){$category=(int)get_post_meta($id,'_rex_category',true);if(!term_exists($category,'rex_download_category')){wp_die('Categorie bestaat niet meer.');}$children=get_term_children($category,'rex_download_category');if(is_wp_error($children)){wp_die('Categorieën ophalen mislukt.');}$categories=array_merge([$category],array_map('intval',$children));$files=get_posts(['post_type'=>'rex_download','post_status'=>'publish','numberposts'=>-1,'tax_query'=>[['taxonomy'=>'rex_download_category','field'=>'term_id','terms'=>$categories,'include_children'=>false]]]);$ids=array_map(function($post){return (int)$post->ID;},$files);update_post_meta($id,'_rex_granted_ids',$ids);$grants=array_values(array_unique(array_merge($grants,$ids)));$state='approved';}
        else {$removed=(array)get_post_meta($id,'_rex_granted_ids',true);$retained=[];foreach(get_posts(['post_type'=>'rex_access_request','post_status'=>'publish','author'=>$user->ID,'numberposts'=>-1]) as $other){if($other->ID!==$id&&get_post_meta($other->ID,'_rex_request_state',true)==='approved'){$retained=array_merge($retained,(array)get_post_meta($other->ID,'_rex_granted_ids',true));}}$grants=array_values(array_diff($grants,array_diff($removed,$retained)));$state=$operation==='deny'?'denied':'revoked';}
        update_user_meta($user->ID,'rex_download_grants',$grants);update_post_meta($id,'_rex_request_state',$state);update_post_meta($id,'_rex_decided_at',time());$this->back('requests');
    }
    public function notify($id,$token,$offset=0,$attempt=1) {
        if(!hash_equals((string)get_post_meta($id,'_rex_notify_token',true),(string)$token)||!self::available($id)){return;}$post=get_post($id);if(!$post){return;}$terms=wp_get_object_terms($id,'rex_download_category',['fields'=>'ids']);$categories=[];foreach(is_wp_error($terms)?[]:$terms as $term){$categories[]=(int)$term;$categories=array_merge($categories,get_ancestors($term,'rex_download_category','taxonomy'));}$keys=array_merge(['file:'.$id],array_map(function($cat){return 'category:'.$cat;},array_unique($categories)));
        $users=get_users(['meta_key'=>'rex_download_watches','number'=>100,'offset'=>(int)$offset,'orderby'=>'ID','order'=>'ASC']);$failed=false;
        foreach($users as $user){if(!array_intersect($keys,self::watches($user->ID))||!self::allowed_for($id,$user)){continue;}$ledger=get_user_meta($user->ID,'rex_download_mail_ledger',true);$ledger=is_array($ledger)?$ledger:[];if(($ledger[$id]??'')===$token){continue;}$subject='Download bijgewerkt: '.$post->post_title;$body=$post->post_title."\nVersie: ".get_post_meta($id,'_rex_version',true)."\n".get_post_meta($id,'_rex_changelog',true)."\n\nBekijk de downloadpagina op ".home_url('/')."\nJe kunt het abonnement op de downloadpagina beëindigen met ‘Niet meer volgen’.";$sent=wp_mail($user->user_email,$subject,$body);update_option('rex_download_last_mail',['time'=>time(),'success'=>(bool)$sent],false);if($sent){$ledger[$id]=$token;update_user_meta($user->ID,'rex_download_mail_ledger',array_slice($ledger,-200,null,true));}else{$failed=true;}}
        if($failed&&$attempt<3){wp_schedule_single_event(time()+300,'rex_download_notify',[$id,$token,$offset,$attempt+1]);}elseif(count($users)===100){wp_schedule_single_event(time()+60,'rex_download_notify',[$id,$token,$offset+100,1]);}
    }
    public function integrity() {
        if(!current_user_can('manage_options')){return;}$report=get_option('rex_download_integrity_report',[]);echo '<div class="wrap"><h1>Bestandscontrole</h1><p>Controleert aanwezigheid, grootte en SHA-256 van huidige bestanden en opgeslagen versies. Oudere bestanden zonder controlewaarde krijgen bij de eerste scan een referentie; eerdere schade is dan niet vast te stellen.</p><button type="button" class="button button-primary" id="rex-integrity-start" data-nonce="'.esc_attr(wp_create_nonce('rex_workflow')).'">Controle starten</button><p id="rex-integrity-status" role="status"></p><ul id="rex-integrity-results">';foreach($report['issues']??[] as $issue){echo '<li>'.esc_html($issue).'</li>';}echo '</ul>';if(!empty($report['time'])){echo '<p>Laatste volledige controle: '.esc_html(wp_date('d-m-Y H:i',$report['time'])).' — '.(int)$report['checked'].' bestanden, '.count($report['issues']).' meldingen.</p>';}echo '</div>';
    }
    public function integrity_begin() {
        $this->ajax_guard(true);$posts=get_posts(['post_type'=>'rex_download','post_status'=>['publish','rex_trash'],'numberposts'=>-1]);$token=wp_generate_uuid4();$state=['owner'=>get_current_user_id(),'token'=>$token,'ids'=>array_map(function($p){return (int)$p->ID;},$posts),'offset'=>0,'checked'=>0,'issues'=>[]];set_transient('rex_integrity_'.get_current_user_id(),$state,HOUR_IN_SECONDS);wp_send_json_success(['token'=>$token,'total'=>count($posts)]);
    }
    public function integrity_batch() {
        $this->ajax_guard(true);$key='rex_integrity_'.get_current_user_id();$state=get_transient($key);if(!$state||!hash_equals($state['token'],sanitize_text_field(wp_unslash($_POST['token']??'')))){wp_send_json_error(['message'=>'Controle verlopen. Start opnieuw.'],400);}$new=[];
        foreach(array_slice($state['ids'],$state['offset'],3) as $id){$versions=get_post_meta($id,'_rex_file_versions',true);$versions=is_array($versions)?$versions:[];$entries=array_merge([['key'=>get_post_meta($id,'_rex_key',true),'size'=>get_post_meta($id,'_rex_size',true),'sha256'=>get_post_meta($id,'_rex_sha256',true)]],$versions);$post=get_post($id);if(!$post){continue;}
            foreach($entries as $index=>$entry){$state['checked']++;$label=$post->post_title.($index?' — oude versie '.$index:'');$path=$this->path($entry['key']??'');if(!$path){$new[]=$label.': bestand ontbreekt.';continue;}$hash=hash_file('sha256',$path);if((int)($entry['size']??0)!==filesize($path)||(!empty($entry['sha256'])&&!hash_equals($entry['sha256'],$hash))){$new[]=$label.': bestand beschadigd of gewijzigd.';}elseif(empty($entry['sha256'])){if(!$index){update_post_meta($id,'_rex_sha256',$hash);}else{$versions[$index-1]['sha256']=$hash;update_post_meta($id,'_rex_file_versions',$versions);}$new[]=$label.': eerste referentie aangemaakt.';}}
        }$state['offset']+=3;$state['issues']=array_merge($state['issues'],$new);$done=$state['offset']>=count($state['ids']);if($done){update_option('rex_download_integrity_report',['time'=>time(),'checked'=>$state['checked'],'issues'=>$state['issues']],false);delete_transient($key);}else{set_transient($key,$state,HOUR_IN_SECONDS);}wp_send_json_success(['done'=>$done,'checked'=>$state['checked'],'issues'=>$new]);
    }
    public function dashboard() {
        if(!current_user_can('manage_options')){return;}global $wpdb;$posts=get_posts(['post_type'=>'rex_download','post_status'=>'publish','numberposts'=>-1]);$available=0;foreach($posts as $post){$available+=self::available($post->ID)?1:0;}$pending=0;foreach(get_posts(['post_type'=>'rex_access_request','post_status'=>'publish','numberposts'=>-1]) as $request){$pending+=get_post_meta($request->ID,'_rex_request_state',true)==='pending'?1:0;}$trash=count(get_posts(['post_type'=>'rex_download','post_status'=>'rex_trash','numberposts'=>-1]));
        echo '<div class="wrap"><h1>Download-dashboard</h1><p><strong>'.count($posts).' downloads · '.$available.' beschikbaar · '.(count($posts)-$available).' gepland/verlopen · '.$trash.' in prullenbak · '.$pending.' open toegangsaanvragen</strong></p><p><a class="button" href="'.esc_url(admin_url('admin.php?page=rex-download-manager')).'">Bestanden beheren</a> <a class="button" href="'.esc_url(admin_url('admin.php?page=rex-download-requests')).'">Aanvragen beoordelen</a></p><h2>Populaire downloads</h2><ol>';
        $rows=$wpdb->get_results("SELECT download_id, COUNT(*) AS total FROM {$wpdb->prefix}rex_download_events WHERE event_type = 'download' GROUP BY download_id ORDER BY total DESC LIMIT 10");foreach($rows?:[] as $row){$post=get_post($row->download_id);if($post){echo '<li>'.esc_html($post->post_title).' — '.(int)$row->total.' downloads</li>';}}echo '</ol><h2>Recent bijgewerkt</h2><ul>';usort($posts,function($a,$b){return (int)get_post_meta($b->ID,'_rex_updated_at',true)<=>(int)get_post_meta($a->ID,'_rex_updated_at',true);});foreach(array_slice($posts,0,10) as $post){echo '<li><a href="'.esc_url(admin_url('admin.php?page=rex-downloads&edit='.$post->ID)).'">'.esc_html($post->post_title).'</a></li>';}echo '</ul>';$report=get_option('rex_download_integrity_report',[]);if($report){echo '<p>Laatste bestandscontrole: '.esc_html(wp_date('d-m-Y H:i',$report['time'])).' — '.count($report['issues']).' meldingen.</p>';}$mail=get_option('rex_download_last_mail',[]);if($mail){echo '<p>Laatste e-mailpoging: '.esc_html(wp_date('d-m-Y H:i',$mail['time'])).' — '.($mail['success']?'door WordPress aangenomen':'verzenden mislukt').'.</p>';}echo '</div>';
    }
    public static function pdf_dialog() {return '<dialog class="rex-pdf-dialog"><div class="rex-pdf-bar"><strong>PDF bekijken</strong><a class="rex-pdf-download">Download</a><a class="rex-pdf-external" target="_blank" rel="noopener">Open apart</a><button type="button" class="rex-pdf-close">Sluiten</button></div><iframe title="PDF-voorbeeld" referrerpolicy="same-origin"></iframe></dialog>';}
    public static function styles() {return '<style>.rex-category-actions{display:flex;flex-wrap:wrap;gap:8px;padding:8px 0}.rex-workflow-status{font-size:.85em}.rex-pdf-dialog{width:min(1000px,92vw);height:85vh;padding:0;border:0;border-radius:10px}.rex-pdf-dialog::backdrop{background:#0009}.rex-pdf-bar{display:flex;align-items:center;flex-wrap:wrap;gap:16px;padding:12px;background:#f1f5f9}.rex-pdf-dialog iframe{width:100%;height:calc(100% - 70px);border:0}.rex-watch,.rex-request{cursor:pointer}</style>';}
}
