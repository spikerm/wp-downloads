<?php
if (!defined('ABSPATH')) { exit; }
final class Rex_Downloads_Features {
    private $core;
    public function __construct($core) {
        $this->core = $core;
        add_action('init',[$this,'install']);
        add_action('rex_download_prune',[$this,'prune']);
        add_action('admin_menu',[$this,'menus'],25);
        add_action('admin_enqueue_scripts',[$this,'assets']);
        add_action('admin_post_rex_save',[$this,'bulk'],1);
        add_action('admin_post_rex_save_settings',[$this,'save_settings']);
        add_action('admin_post_rex_sort_categories',[$this,'sort_categories']);
        add_action('admin_post_rex_preview',[$this,'preview']);
        add_action('admin_post_nopriv_rex_preview',[$this,'preview']);
        add_action('wp_ajax_rex_favorite',[$this,'favorite']);
        add_action('rex_download_extra_fields',[$this,'download_fields']);
        add_action('rex_download_saved',[$this,'saved'],10,3);
        add_action('rex_category_fields',[$this,'category_fields']);
        add_action('rex_category_saved',[$this,'category_saved']);
        add_action('rex_category_sort_fields',[$this,'sort_fields']);
        add_action('rex_download_served',[$this,'record'],10,2);
        require_once __DIR__.'/migration.php';
        new Rex_Downloads_Migration($core);
    }
    public static function settings() {
        $value = get_option('rex_download_settings',[]);
        return array_merge(['accent'=>'#17639e','density'=>'normal','collapsed'=>0,'badge_days'=>14,'log_users'=>1,'retention'=>90,'guest_access'=>0],is_array($value)?$value:[]);
    }
    private function guard($nonce) {
        if (!current_user_can('manage_options')) { wp_die('Geen toegang.','',['response'=>403]); }
        check_admin_referer($nonce);
    }
    public function install() {
        if (get_option('rex_download_db_version') !== '1') {
            global $wpdb;
            require_once ABSPATH.'wp-admin/includes/upgrade.php';
            dbDelta('CREATE TABLE '.$wpdb->prefix.'rex_download_events (id bigint(20) unsigned NOT NULL AUTO_INCREMENT, download_id bigint(20) unsigned NOT NULL, user_id bigint(20) unsigned NOT NULL DEFAULT 0, event_type varchar(16) NOT NULL, occurred_at datetime NOT NULL, PRIMARY KEY  (id), KEY download_event (download_id,event_type), KEY occurred_at (occurred_at)) '.$wpdb->get_charset_collate().';');
            update_option('rex_download_db_version','1',false);
        }
        if (!wp_next_scheduled('rex_download_prune')) { wp_schedule_event(time()+HOUR_IN_SECONDS,'daily','rex_download_prune'); }
    }
    public function prune() {
        global $wpdb;
        $cutoff = gmdate('Y-m-d H:i:s',time()-DAY_IN_SECONDS*self::settings()['retention']);
        $wpdb->query($wpdb->prepare('DELETE FROM '.$wpdb->prefix.'rex_download_events WHERE occurred_at < %s',$cutoff));
    }
    public function menus() {
        foreach (['stats'=>'Statistieken','log'=>'Downloadlogboek','settings'=>'Vormgeving & instellingen'] as $page=>$label) {
            add_submenu_page('rex-downloads',$label,$label,'manage_options','rex-download-'.$page,[$this,$page==='settings'?'settings_admin':$page]);
        }
    }
    public function assets() {
        if (($_GET['page'] ?? '') === 'rex-download-categories') {
            wp_enqueue_script('rex-category-sort',plugins_url('category-sort.js',__FILE__),['jquery','jquery-ui-sortable'],'3.1.0',true);
        }
    }
    public function sort_fields() {
        echo '<p>Versleep met ↕ binnen dezelfde hoofdcategorie en klik daarna op Volgorde opslaan. Omhoog/Omlaag blijft beschikbaar.</p><form method="post" action="'.esc_url(admin_url('admin-post.php')).'" id="rex-sort-form">';
        wp_nonce_field('rex_sort_categories');
        echo '<input type="hidden" name="action" value="rex_sort_categories"><input type="hidden" name="order" id="rex-sort-order"><button class="button button-primary">Volgorde opslaan</button><span id="rex-sort-message" role="status" style="margin-left:10px"></span></form>';
    }
    public function sort_categories() {
        $this->guard('rex_sort_categories');
        $ids = json_decode(wp_unslash($_POST['order'] ?? ''),true);
        if (!is_array($ids) || count($ids)>5000) { wp_die('Ongeldige volgorde.'); }
        $terms = get_terms(['taxonomy'=>'rex_download_category','hide_empty'=>false]);
        if (is_wp_error($terms)) { wp_die('Categorieën niet beschikbaar.'); }
        $map=[];foreach($terms as $term){$map[(int)$term->term_id]=(int)$term->parent;}
        $ids=array_map('absint',$ids);
        if(count($ids)!==count($map)||count(array_unique($ids))!==count($map)||array_diff($ids,array_keys($map))){wp_die('De categorieën zijn veranderd. Herlaad de pagina.');}
        $order=[];$positions=[];
        foreach($ids as $id){$parent=$map[$id];$order[$id]=$positions[$parent]??0;$positions[$parent]=($positions[$parent]??0)+1;}
        update_option('rex_download_category_order',$order,false);
        wp_safe_redirect(admin_url('admin.php?page=rex-download-categories'));exit;
    }
    public static function roles($input) {
        $input=is_array($input)?$input:[];
        return array_values(array_unique(array_intersect(array_map(function($role){return in_array($role,['*','@guest'],true)?$role:(is_string($role)?sanitize_key($role):'');},$input),array_merge(['*','@guest'],array_keys(wp_roles()->roles)))));
    }
    private static function role_boxes($name,$selected) {
        foreach(array_merge(['*'=>'Alle ingelogde gebruikers','@guest'=>'Gasten (openbaar, ook voor ingelogde gebruikers)'],wp_roles()->get_names()) as $key=>$label){
            echo '<label style="display:block;margin:6px 0"><input type="checkbox" name="'.esc_attr($name).'[]" value="'.esc_attr($key).'" '.checked(in_array($key,$selected,true),true,false).'> '.esc_html(translate_user_role($label)).'</label>';
        }
    }
    public function category_fields($id) {
        $mode=$id?get_term_meta($id,'_rex_access_mode',true):'';
        $roles=$id?(array)get_term_meta($id,'_rex_roles',true):['*'];
        echo '<h3>Toegang voor bestanden die categorietoegang gebruiken</h3><select name="category_access_mode"><option value="inherit" '.selected($mode,'inherit',false).'>Overnemen van bovenliggende categorie (hoofdcategorie: alle ingelogde gebruikers)</option><option value="custom" '.selected($mode,'custom',false).'>Eigen rollen voor deze categorie</option></select>';
        self::role_boxes('category_roles',$roles);
        echo '<p>Eigen rollen zonder vinkjes: alleen beheerders. Downloads met eigen toegangsrollen blijven hun eigen instelling gebruiken.</p>';
    }
    public function category_saved($id) {
        if(!isset($_POST['category_access_mode'])){return;}
        update_term_meta($id,'_rex_access_mode',($_POST['category_access_mode']==='custom')?'custom':'inherit');
        update_term_meta($id,'_rex_roles',self::roles(wp_unslash($_POST['category_roles']??[])));
    }
    public static function effective_category_roles($id) {
        $seen=[];
        while($id && !isset($seen[$id])){
            $seen[$id]=true;
            if(get_term_meta($id,'_rex_access_mode',true)==='custom'){return self::roles(get_term_meta($id,'_rex_roles',true));}
            $term=get_term($id,'rex_download_category');
            if(!$term||is_wp_error($term)){return [];}
            $id=(int)$term->parent;
        }
        return $id ? [] : ['*'];
    }
    public static function category_access($id,$user=null) {
        $terms=wp_get_object_terms($id,'rex_download_category',['fields'=>'ids']);
        if(is_wp_error($terms)||!$terms){return false;}
        $user=$user?:wp_get_current_user();
        foreach($terms as $term){$roles=self::effective_category_roles((int)$term);if(empty($user->ID)){if(self::settings()['guest_access']&&in_array('@guest',$roles,true)){return true;}}elseif(in_array('@guest',$roles,true)||in_array('*',$roles,true)||array_intersect($roles,(array)$user->roles)){return true;}}
        return false;
    }
    public static function guest_access($id) {
        if(!self::settings()['guest_access']){return false;}
        if(get_post_meta($id,'_rex_access_mode',true)==='inherit'){$terms=wp_get_object_terms($id,'rex_download_category',['fields'=>'ids']);foreach(is_wp_error($terms)?[]:$terms as $term){if(in_array('@guest',self::effective_category_roles((int)$term),true)){return true;}}return false;}
        return in_array('@guest',(array)get_post_meta($id,'_rex_roles',true),true);
    }
    public function download_fields($id) {
        echo '<p><label>Toegangsbron <select name="access_mode"><option value="explicit">Eigen rollen hierboven</option><option value="inherit" '.selected($id?get_post_meta($id,'_rex_access_mode',true):'','inherit',false).'>Rollen van de categorie overnemen</option></select></label></p><p>Bij meerdere categorieën is toegang via één categorie voldoende. Zonder categorie: alleen beheerders bij overnemen.</p></td></tr><tr><th>Versie & wijzigingen</th><td>';
        echo '<p><label>Versie <input class="regular-text" name="version" maxlength="40" value="'.esc_attr($id?get_post_meta($id,'_rex_version',true):'').'" placeholder="Bijvoorbeeld 1.0.0"></label></p><label>Wijzigingen in deze versie<textarea class="large-text" rows="4" name="changelog">'.esc_textarea($id?get_post_meta($id,'_rex_changelog',true):'').'</textarea></label>';
        if($id){$history=get_post_meta($id,'_rex_history',true);if(is_array($history)&&$history){echo '<details><summary>Versiegeschiedenis</summary><ul>';foreach(array_reverse($history) as $entry){echo '<li>'.esc_html(wp_date('d-m-Y H:i',$entry['time']).' — '.($entry['version']?:'Zonder versienummer').' — '.$entry['notes']).'</li>';}echo '</ul></details>';}}
    }
    public function saved($id,$replaced,$existing) {
        $version=substr(sanitize_text_field(wp_unslash($_POST['version']??'')),0,40);
        $notes=sanitize_textarea_field(wp_unslash($_POST['changelog']??''));
        $old_version=get_post_meta($id,'_rex_version',true);$old_notes=get_post_meta($id,'_rex_changelog',true);
        if(!$existing || $replaced || $version!==$old_version || $notes!==$old_notes){
            $history=get_post_meta($id,'_rex_history',true);$history=is_array($history)?$history:[];
            $history[]=['time'=>time(),'version'=>$version,'notes'=>$notes];
            update_post_meta($id,'_rex_history',array_slice($history,-50));
            update_post_meta($id,'_rex_updated_at',time());
        }
        if(!get_post_meta($id,'_rex_created_at',true)){$post=get_post($id);$created=$existing&&!empty($post->post_date_gmt)?strtotime($post->post_date_gmt.' UTC'):time();update_post_meta($id,'_rex_created_at',$created?:time());}
        if(!get_post_meta($id,'_rex_uuid',true)){update_post_meta($id,'_rex_uuid',wp_generate_uuid4());}
        update_post_meta($id,'_rex_version',$version);update_post_meta($id,'_rex_changelog',$notes);
        update_post_meta($id,'_rex_access_mode',($_POST['access_mode']??'')==='inherit'?'inherit':'explicit');
    }
    public function bulk() {
        $ids=json_decode(wp_unslash($_POST['media_ids']??''),true);
        if(!is_array($ids)||count($ids)<2){return;}
        $this->guard('rex_manage');
        if(!empty($_POST['id'])||count($ids)>50){wp_die('Selecteer maximaal 50 bestanden voor een nieuwe bulkimport.');}
        $ids=array_values(array_unique(array_map('absint',$ids)));$names=[];
        foreach($ids as $id){$source=Rex_Downloads_Migration::media_source($id);if(is_wp_error($source)){wp_die(esc_html($source->get_error_message()));}$names[$id]=sanitize_file_name(basename($source));}
        $count=0;
        foreach($ids as $id){$_POST['id']=0;$_POST['media_id']=$id;$_POST['title']=$names[$id];$this->core->save(false);$count++;}
        wp_safe_redirect(admin_url('admin.php?page=rex-download-all&imported='.$count));exit;
    }
    public static function favorites() {
        $ids=get_user_meta(get_current_user_id(),'rex_download_favorites',true);
        return is_array($ids)?array_values(array_unique(array_map('absint',$ids))):[];
    }
    public function favorite() {
        if(!is_user_logged_in()){wp_send_json_error(['message'=>'Log eerst in.'],401);}
        check_ajax_referer('rex_favorite','nonce');
        $id=absint($_POST['id']??0);
        if(get_post_type($id)!=='rex_download'||get_post_status($id)!=='publish'||!$this->core->allowed($id)){wp_send_json_error(['message'=>'Geen toegang tot dit bestand.'],403);}
        $ids=self::favorites();$active=($_POST['active']??'')==='1';
        if($active){$ids[]=$id;}else{$ids=array_diff($ids,[$id]);}
        if(count($ids)>1000){wp_send_json_error(['message'=>'Maximaal 1000 favorieten.'],400);}
        update_user_meta(get_current_user_id(),'rex_download_favorites',array_values(array_unique($ids)));
        wp_send_json_success(['active'=>$active]);
    }
    public function record($id,$type) {
        global $wpdb;
        $wpdb->insert($wpdb->prefix.'rex_download_events',['download_id'=>$id,'user_id'=>self::settings()['log_users']?get_current_user_id():0,'event_type'=>$type==='preview'?'preview':'download','occurred_at'=>gmdate('Y-m-d H:i:s')],['%d','%d','%s','%s']);
    }
    public function preview() {
        $id=absint($_GET['id']??0);
        if(get_post_type($id)!=='rex_download'||get_post_status($id)!=='publish'||!$this->core->allowed($id)){wp_die('Geen toegang.','',['response'=>403]);}
        check_admin_referer('rex_preview_'.$id);
        $file=$this->core->file($id);$name=get_post_meta($id,'_rex_name',true);
        if(!$file||strtolower(pathinfo($name,PATHINFO_EXTENSION))!=='pdf'){wp_die('Geen PDF beschikbaar.','',['response'=>404]);}
        $handle=fopen($file,'rb');if(!$handle){wp_die('PDF niet beschikbaar.');}
        if(strncmp(fread($handle,5),'%PDF-',5)!==0){fclose($handle);wp_die('Dit bestand is geen geldige PDF.');}rewind($handle);
        while(ob_get_level()){ob_end_clean();}nocache_headers();
        header('Content-Type: application/pdf');header('X-Content-Type-Options: nosniff');header('X-Frame-Options: SAMEORIGIN');
        header('Content-Disposition: inline; filename="preview.pdf"; filename*=UTF-8\'\''.rawurlencode($name));header('Content-Length: '.filesize($file));
        $this->record($id,'preview');
        while(!feof($handle)){echo fread($handle,1024*1024);flush();if(connection_aborted()){break;}}
        fclose($handle);exit;
    }
    public function stats() {
        if(!current_user_can('manage_options')){return;}global $wpdb;$table=$wpdb->prefix.'rex_download_events';
        $totals=$wpdb->get_results("SELECT event_type, COUNT(*) AS total FROM $table GROUP BY event_type",OBJECT_K);
        echo '<div class="wrap"><h1>Downloadstatistieken</h1><p>Downloads: <strong>'.(int)($totals['download']->total??0).'</strong> · PDF-voorbeelden: <strong>'.(int)($totals['preview']->total??0).'</strong></p><p>Binnen de bewaartermijn van '.(int)self::settings()['retention'].' dagen. Een telling betekent dat de overdracht is gestart, niet dat deze volledig is afgerond.</p><h2>Per bestand</h2><table class="widefat striped"><thead><tr><th>Bestand</th><th>Downloads</th><th>PDF-voorbeelden</th><th>Laatst gebruikt</th></tr></thead><tbody>';
        $rows=$wpdb->get_results("SELECT e.download_id, p.post_title, SUM(e.event_type='download') AS downloads, SUM(e.event_type='preview') AS previews, MAX(e.occurred_at) AS recent FROM $table e LEFT JOIN {$wpdb->posts} p ON p.ID=e.download_id GROUP BY e.download_id,p.post_title ORDER BY downloads DESC LIMIT 200");
        foreach($rows as $row){echo '<tr><td>'.esc_html($row->post_title?:'Verwijderd bestand #'.$row->download_id).'</td><td>'.(int)$row->downloads.'</td><td>'.(int)$row->previews.'</td><td>'.esc_html(get_date_from_gmt($row->recent,'d-m-Y H:i')).'</td></tr>';}
        echo '</tbody></table><h2>Per gebruiker</h2><table class="widefat striped"><thead><tr><th>Gebruiker</th><th>Downloads</th></tr></thead><tbody>';
        $rows=$wpdb->get_results("SELECT e.user_id,u.display_name,COUNT(*) AS total FROM $table e LEFT JOIN {$wpdb->users} u ON u.ID=e.user_id WHERE e.event_type='download' GROUP BY e.user_id,u.display_name ORDER BY total DESC LIMIT 200");
        foreach($rows as $row){echo '<tr><td>'.esc_html($row->user_id?($row->display_name?:'Verwijderde gebruiker'):'Anoniem geregistreerd').'</td><td>'.(int)$row->total.'</td></tr>';}
        echo '</tbody></table></div>';
    }
    public function log() {
        if(!current_user_can('manage_options')){return;}global $wpdb;$page=max(1,absint($_GET['paged']??1));$offset=($page-1)*50;$table=$wpdb->prefix.'rex_download_events';
        $rows=$wpdb->get_results($wpdb->prepare("SELECT e.*,p.post_title,u.display_name FROM $table e LEFT JOIN {$wpdb->posts} p ON p.ID=e.download_id LEFT JOIN {$wpdb->users} u ON u.ID=e.user_id ORDER BY e.id DESC LIMIT 51 OFFSET %d",$offset));
        echo '<div class="wrap"><h1>Downloadlogboek</h1><p>Download- en PDF-openingen met tijdstip. Bewaartermijn: '.(int)self::settings()['retention'].' dagen. IP-adressen worden niet opgeslagen.</p><table class="widefat striped"><thead><tr><th>Datum</th><th>Gebruiker</th><th>Bestand</th><th>Actie</th></tr></thead><tbody>';
        foreach(array_slice($rows,0,50) as $row){echo '<tr><td>'.esc_html(get_date_from_gmt($row->occurred_at,'d-m-Y H:i:s')).'</td><td>'.esc_html($row->user_id?($row->display_name?:'Verwijderde gebruiker'):'Anoniem geregistreerd').'</td><td>'.esc_html($row->post_title?:'Verwijderd bestand #'.$row->download_id).'</td><td>'.esc_html($row->event_type==='preview'?'PDF-voorbeeld':'Download').'</td></tr>';}
        echo '</tbody></table><p>';if($page>1){echo '<a class="button" href="'.esc_url(admin_url('admin.php?page=rex-download-log&paged='.($page-1))).'">Vorige</a> ';}if(count($rows)>50){echo '<a class="button" href="'.esc_url(admin_url('admin.php?page=rex-download-log&paged='.($page+1))).'">Volgende</a>';}echo '</p></div>';
    }
    public function settings_page() {
        if(!current_user_can('manage_options')){return;}$s=self::settings();echo '<div class="wrap"><h1>Vormgeving & instellingen</h1><form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';wp_nonce_field('rex_settings');echo '<input type="hidden" name="action" value="rex_save_settings"><table class="form-table"><tr><th>Gasttoegang</th><td><label><input type="checkbox" name="guest_access" value="1" '.checked($s['guest_access'],1,false).'> Downloads voor gasten toestaan</label><p>Gasten zien en downloaden uitsluitend bestanden met toegang ‘Gasten’, via eigen rollen of categorietoegang. Bestaande afgeschermde bestanden blijven afgeschermd. Favorieten, abonnementen en aanvragen vereisen inloggen.</p></td></tr><tr><th>Accentkleur</th><td><input type="color" name="accent" value="'.esc_attr($s['accent']).'"></td></tr><tr><th>Weergave</th><td><select name="density">';foreach(['compact'=>'Compact','normal'=>'Normaal','spacious'=>'Ruim'] as $value=>$label){echo '<option value="'.esc_attr($value).'" '.selected($s['density'],$value,false).'>'.esc_html($label).'</option>';}echo '</select></td></tr><tr><th>Mappen</th><td><label><input type="checkbox" name="collapsed" value="1" '.checked($s['collapsed'],1,false).'> Standaard ingeklapt</label></td></tr><tr><th>Nieuw / bijgewerkt</th><td><input type="number" name="badge_days" min="1" max="365" value="'.(int)$s['badge_days'].'"> dagen zichtbaar</td></tr><tr><th>Logboek</th><td><label><input type="checkbox" name="log_users" value="1" '.checked($s['log_users'],1,false).'> Gebruiker vastleggen bij downloads en PDF-voorbeelden</label><p>Uitgeschakeld: nieuwe gebeurtenissen worden zonder gebruikerskoppeling geregistreerd.</p><label>Bewaren <input type="number" name="retention" min="1" max="3650" value="'.(int)$s['retention'].'"> dagen</label></td></tr></table>';submit_button('Instellingen opslaan');echo '</form></div>';
    }
    public function settings_admin(){ $this->settings_page(); }
    public function save_settings() {
        $this->guard('rex_settings');
        $s=self::clean_settings(wp_unslash($_POST));update_option('rex_download_settings',$s,false);$this->prune();
        wp_safe_redirect(admin_url('admin.php?page=rex-download-settings'));exit;
    }
    public static function clean_settings($input) {
        return ['guest_access'=>empty($input['guest_access'])?0:1,'accent'=>sanitize_hex_color($input['accent']??'')?:'#17639e','density'=>in_array($input['density']??'',['compact','normal','spacious'],true)?$input['density']:'normal','collapsed'=>empty($input['collapsed'])?0:1,'badge_days'=>max(1,min(365,absint($input['badge_days']??14))),'log_users'=>empty($input['log_users'])?0:1,'retention'=>max(1,min(3650,absint($input['retention']??90)))];
    }
    public static function toolbar($rows) {
        echo '<div class="rex-toolbar"><label>Zoeken<input class="rex-search" type="search" placeholder="Naam of beschrijving…"></label><label>Categorie<select class="rex-filter-category"><option value="">Alle categorieën</option>';
        foreach($rows as $row){echo '<option value="'.(int)$row['term']->term_id.'">'.esc_html(str_repeat('— ',$row['depth']).$row['term']->name).'</option>';}
        echo '</select></label><label>Bestandstype<select class="rex-filter-type"><option value="">Alle typen</option></select></label><label class="rex-favorites-filter" '.(is_user_logged_in()?'':'hidden').'><input class="rex-filter-favorites" type="checkbox"> Alleen favorieten</label><button class="rex-expand" type="button">Alles uitklappen</button><button class="rex-collapse" type="button">Alles inklappen</button></div><p class="rex-filter-status" role="status" aria-live="polite"></p>';
    }
    public static function styles() {
        return '<style>.rex-downloads h2{border-color:var(--rex-accent)}.rex-downloads a,.rex-downloads summary::marker,.rex-downloads .rex-folder-icon{color:var(--rex-accent)}.rex-toolbar{display:flex;align-items:end;gap:10px;flex-wrap:wrap;margin-bottom:12px}.rex-toolbar label{font-size:12px;display:flex;flex-direction:column;gap:4px}.rex-toolbar input[type=search],.rex-toolbar select{padding:8px;border:1px solid #ccd5df;background:#fff;color:#163b60;max-width:100%;border-radius:4px}.rex-toolbar .rex-favorites-filter{flex-direction:row;align-items:center;padding:8px}.rex-downloads button{cursor:pointer;border:1px solid #c9d4df;background:#f4f7fa;color:var(--rex-accent);padding:6px 9px;border-radius:4px;font:inherit}.rex-downloads button:focus-visible{outline:3px solid var(--rex-accent)}.rex-file-group{margin:7px 0}.rex-file-group .rex-file{margin:0}.rex-file-actions{display:flex;gap:6px;align-items:center;flex-wrap:wrap;padding:5px 8px;font-size:12px}.rex-badge{font-size:10px;font-weight:700;border-radius:4px;padding:2px 6px;background:#e3f5e9;color:#236b3d}.rex-badge-updated{background:#e3efff;color:#225b91}.rex-version{font-size:11px;color:#677a8b}.rex-favorite[aria-pressed=true]{color:#9b6900;background:#fff7dc}.rex-downloads [hidden]{display:none!important}.rex-changelog{font-size:12px;padding:8px;border:1px solid #d7e1eb;border-radius:4px;white-space:pre-line}.rex-filter-status{font-size:12px;color:#617488}.rex-density-compact .rex-file{padding:5px 7px}.rex-density-compact .rex-folder summary{padding:6px 8px}.rex-density-spacious .rex-file{padding:15px}.rex-density-spacious .rex-folder summary{padding:15px}@media(max-width:600px){.rex-toolbar label{max-width:100%}.rex-toolbar .rex-search{width:100%}}</style>';
    }
}
