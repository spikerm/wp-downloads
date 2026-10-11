<?php
/**
 * Plugin Name: WP Dares Downloads
 * Description: Beveiligde downloadbibliotheek met rollen, categorieën, zoeken, favorieten, versies en statistieken. Shortcode: [rex_downloads].
 * Version: 3.0.0
 * Plugin URI: https://github.com/spikerm/wp-downloads
 * Update URI: https://github.com/spikerm/wp-downloads
 * Author: Rex Markesteijn
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * License: GPL-2.0-or-later
 */
if (!defined('ABSPATH')) { exit; }
final class Rex_Downloads {
    public function __construct() {
        add_action('init', [$this,'register']);
        add_action('admin_post_rex_category',[$this,'save_category']);
        add_action('admin_post_rex_order_category',[$this,'order_category']);
        add_action('admin_post_rex_delete_category',[$this,'delete_category']);
        add_action('admin_enqueue_scripts',function(){
            if (isset($_GET['page']) && in_array(sanitize_key($_GET['page']), ['rex-downloads','rex-download-all','rex-download-new'],true)) { wp_enqueue_media(); wp_enqueue_script('rex-download-media',plugins_url('media-selector.js',__FILE__),['jquery','media-editor'],'3.0.0',true); }
        });
        add_action('admin_post_rex_create_role',[$this,'create_role']);
        add_action('show_user_profile',[$this,'user_roles']);
        add_action('edit_user_profile',[$this,'user_roles']);
        add_action('profile_update',[$this,'save_user_roles']);
        add_action('admin_menu', [$this,'register_admin_menu']);
        add_action('admin_post_rex_save',[$this,'save']);
        add_action('admin_post_rex_delete',[$this,'delete']);
        add_action('admin_post_rex_download',[$this,'download']);
        add_action('admin_post_nopriv_rex_download',function(){ auth_redirect(); exit; });
        add_shortcode('rex_downloads',[$this,'shortcode']);
        add_action('template_redirect',function(){
            if (is_singular() && has_shortcode((string)get_post_field('post_content',get_queried_object_id()),'rex_downloads')) {
                if (!defined('DONOTCACHEPAGE')) { define('DONOTCACHEPAGE',true); }
                nocache_headers();
            }
        });
    }
    public function register_admin_menu() {
        // WordPress must know the parent before calculating submenu page hooks.
        add_menu_page('Downloads','Downloads','manage_options','rex-downloads',[$this,'admin'],'dashicons-download');
        add_submenu_page('rex-downloads','Mijn Downloads','Mijn Downloads','manage_options','rex-downloads',[$this,'admin']);
        add_submenu_page('rex-downloads','Alle Downloads','Alle Downloads','manage_options','rex-download-all',[$this,'admin']);
        add_submenu_page('rex-downloads','Nieuw bestand','Nieuw bestand','manage_options','rex-download-new',[$this,'admin']);
        add_submenu_page('rex-downloads','Categorieën','Categorieën','manage_options','rex-download-categories',[$this,'categories_admin']);
        add_submenu_page('rex-downloads','Downloadrollen','Rollen','manage_options','rex-download-roles',[$this,'roles_admin']);
    }
    public function register() {
        register_post_type('rex_download',['public'=>false,'show_ui'=>false,'supports'=>['title','editor','author']]);
        register_taxonomy('rex_download_category','rex_download',['public'=>false,'show_ui'=>false,'hierarchical'=>true]);
    }
    public function category_tree() {
        $terms = get_terms(['taxonomy'=>'rex_download_category','hide_empty'=>false,'orderby'=>'name','order'=>'ASC']);
        if (is_wp_error($terms)) { return []; }
        $terms = $this->ordered_categories($terms);
        $children = []; $rows = []; $seen = [];
        foreach ($terms as $term) { $children[(int)$term->parent][] = $term; }
        $walk = function($parent,$depth) use (&$walk,&$children,&$rows,&$seen) {
            foreach ($children[$parent] ?? [] as $term) {
                if (isset($seen[$term->term_id])) { continue; }
                $seen[$term->term_id] = true;
                $rows[] = ['term'=>$term,'depth'=>$depth];
                $walk((int)$term->term_id,$depth+1);
            }
        };
        $walk(0,0);
        foreach ($terms as $term) {
            if (!isset($seen[$term->term_id])) {
                $seen[$term->term_id] = true;
                $rows[] = ['term'=>$term,'depth'=>0];
                $walk((int)$term->term_id,1);
            }
        }
        return $rows;
    }
    private function ordered_categories($terms) {
        $order = get_option('rex_download_category_order',[]);
        if (!is_array($order)) { $order = []; }
        usort($terms,function($a,$b) use ($order) {
            $comparison = ($order[$a->term_id] ?? PHP_INT_MAX) <=> ($order[$b->term_id] ?? PHP_INT_MAX);
            return $comparison ?: (strnatcasecmp($a->name,$b->name) ?: ($a->term_id <=> $b->term_id));
        });
        return $terms;
    }
    public function order_category() {
        $this->guard();
        $id = absint($_POST['category_id'] ?? 0);
        $direction = sanitize_key(wp_unslash($_POST['direction'] ?? ''));
        $term = $id ? get_term($id,'rex_download_category') : null;
        if (!$term || is_wp_error($term) || !in_array($direction,['up','down'],true)) { wp_die('Ongeldige categorie of richting.'); }
        $siblings = get_terms(['taxonomy'=>'rex_download_category','hide_empty'=>false,'parent'=>(int)$term->parent]);
        if (is_wp_error($siblings)) { wp_die('Categorieën konden niet worden opgehaald.'); }
        $siblings = $this->ordered_categories($siblings);
        $ids = array_map(function($item){ return (int)$item->term_id; },$siblings);
        $index = array_search($id,$ids,true);
        if ($index === false) { wp_die('Categorie niet gevonden.'); }
        $target = $index + ($direction === 'up' ? -1 : 1);
        if (isset($ids[$target])) {
            $ids[$index] = $ids[$target]; $ids[$target] = $id;
            $order = get_option('rex_download_category_order',[]);
            if (!is_array($order)) { $order = []; }
            foreach ($ids as $position=>$term_id) { $order[$term_id] = $position; }
            update_option('rex_download_category_order',$order,false);
        }
        wp_safe_redirect(admin_url('admin.php?page=rex-download-categories')); exit;
    }
    public function categories_admin() {
        if (!current_user_can('manage_options')) { return; }
        $edit_id = absint($_GET['edit_category'] ?? 0);
        $edit = $edit_id ? get_term($edit_id,'rex_download_category') : null;
        if ($edit_id && (!$edit || is_wp_error($edit))) { wp_die('Categorie niet gevonden.'); }
        echo '<div class="wrap"><h1>Downloadcategorieën</h1><h2>'.($edit ? 'Categorie bewerken' : 'Nieuwe categorie').'</h2><form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';
        wp_nonce_field('rex_manage');
        echo '<input type="hidden" name="action" value="rex_category"><input type="hidden" name="category_id" value="'.(int)$edit_id.'"><label for="rex-cat-name">Naam</label> <input id="rex-cat-name" name="category_name" value="'.esc_attr($edit ? $edit->name : '').'" required class="regular-text">';
        echo '<p><label for="rex-category-parent">Hoofdcategorie</label> ';
        echo '<select name="category_parent" id="rex-category-parent"><option value="0">Geen — maak een hoofdcategorie</option>';
        $excluded = $edit_id ? get_term_children($edit_id,'rex_download_category') : [];
        if (is_wp_error($excluded)) { $excluded = []; }
        $excluded[] = $edit_id;
        foreach ($this->category_tree() as $row) {
            $term = $row['term'];
            if (in_array($term->term_id,$excluded)) { continue; }
            echo '<option value="'.(int)$term->term_id.'" '.selected($edit ? (int)$edit->parent : 0,(int)$term->term_id,false).'>'.esc_html(str_repeat('— ',$row['depth']).$term->name).'</option>';
        }
        echo '</select>';

        echo '</p><p class="description">Kies een bestaande categorie om een subcategorie te maken. Ook meerdere niveaus zijn mogelijk.</p>';
        do_action('rex_category_fields',$edit_id);
        submit_button($edit ? 'Categorie opslaan' : 'Categorie toevoegen');
        if ($edit) { echo '<p><a href="'.esc_url(admin_url('admin.php?page=rex-download-categories')).'">Bewerken annuleren</a></p>'; }
        echo '</form><table class="widefat striped"><thead><tr><th>Categorie</th><th>Aantal bestanden</th><th>Volgorde</th><th>Acties</th></tr></thead><tbody id="rex-category-sort">';
        foreach ($this->category_tree() as $row) {
            $term = $row['term'];
            echo '<tr data-term-id="'.(int)$term->term_id.'" data-parent-id="'.(int)$term->parent.'"><td><span class="rex-drag-handle" tabindex="0" title="Versleep binnen dezelfde hoofdcategorie" style="cursor:grab;margin-right:8px">↕</span><span style="display:inline-block;padding-left:'.(int)($row['depth']*22).'px">'.($row['depth'] ? '↳ ' : '').esc_html($term->name).'</span></td><td>'.(int)$term->count.'</td><td>';
            foreach (['up'=>'↑ Omhoog','down'=>'↓ Omlaag'] as $direction=>$label) {
                echo '<form style="display:inline-block;margin:2px" method="post" action="'.esc_url(admin_url('admin-post.php')).'">';
                wp_nonce_field('rex_manage');
                echo '<input type="hidden" name="action" value="rex_order_category"><input type="hidden" name="category_id" value="'.(int)$term->term_id.'"><input type="hidden" name="direction" value="'.esc_attr($direction).'"><button class="button" aria-label="'.esc_attr($term->name.' '.$label).'">'.esc_html($label).'</button></form>';
            }
            echo '</td><td><a href="'.esc_url(admin_url('admin.php?page=rex-download-categories&edit_category='.$term->term_id)).'">Bewerken</a><form style="display:inline;margin-left:15px" method="post" action="'.esc_url(admin_url('admin-post.php')).'" onsubmit="return confirm(\'Categorie verwijderen? Downloads blijven bestaan. Subcategorieën schuiven een niveau omhoog.\')">';
            wp_nonce_field('rex_manage');
            echo '<input type="hidden" name="action" value="rex_delete_category"><input type="hidden" name="category_id" value="'.(int)$term->term_id.'"><button class="button-link-delete">Verwijderen</button></form></td></tr>';
        }
        echo '</tbody></table>'; do_action('rex_category_sort_fields'); echo '<p>Gebruik Omhoog en Omlaag om categorieën binnen dezelfde hoofdcategorie te verplaatsen. Subcategorieën verplaatsen mee met hun bovenliggende map. De volgorde geldt ook op de downloadpagina.</p></div>';
    }
    public function save_category() {
        $this->guard();
        $name = sanitize_text_field(wp_unslash($_POST['category_name'] ?? ''));
        if (!$name) { wp_die('Vul een categorienaam in.'); }
        $parent = absint($_POST['category_parent'] ?? 0);
        if ($parent && !term_exists($parent,'rex_download_category')) { wp_die('De gekozen hoofdcategorie bestaat niet.'); }
        $id = absint($_POST['category_id'] ?? 0);
        if ($id) {
            $term = get_term($id,'rex_download_category');
            if (!$term || is_wp_error($term)) { wp_die('Categorie niet gevonden.'); }
            $children = get_term_children($id,'rex_download_category');
            if (is_wp_error($children) || $parent === $id || in_array($parent,array_map('intval',$children),true)) { wp_die('Een categorie kan niet onder zichzelf of een eigen subcategorie staan.'); }
            $result = wp_update_term($id,'rex_download_category',['name'=>$name,'parent'=>$parent]);
        } else {
            $result = wp_insert_term($name,'rex_download_category',['parent'=>$parent]);
        }
        if (is_wp_error($result)) { wp_die(esc_html($result->get_error_message())); }
        do_action('rex_category_saved',(int)$result['term_id']);
        wp_safe_redirect(admin_url('admin.php?page=rex-download-categories')); exit;
    }
    public function delete_category() {
        $this->guard();
        $id = absint($_POST['category_id'] ?? 0);
        $term = $id ? get_term($id,'rex_download_category') : null;
        if (!$term || is_wp_error($term)) { wp_die('Categorie niet gevonden.'); }
        // WordPress reparents direct children and removes only term relationships.
        $result = wp_delete_term($id,'rex_download_category');
        if (is_wp_error($result)) { wp_die(esc_html($result->get_error_message())); }
        if (!$result) { wp_die('Categorie kon niet worden verwijderd.'); }
        wp_safe_redirect(admin_url('admin.php?page=rex-download-categories')); exit;
    }
    private function custom_roles() {
        $roles = get_option('rex_download_custom_roles', []);
        return is_array($roles) ? array_intersect_key($roles, wp_roles()->roles) : [];
    }
    public function create_role() {
        $this->guard();
        $label = sanitize_text_field(wp_unslash($_POST['role_label'] ?? ''));
        $slug = sanitize_key(wp_unslash($_POST['role_slug'] ?? ''));
        if (!$label || !$slug) { wp_die('Vul een naam en een geldige rolcode in.'); }
        $key = 'rex_' . $slug;
        if (get_role($key)) { wp_die('Deze rolcode bestaat al. Kies een andere code.'); }
        if (!add_role($key, $label, ['read'=>true])) { wp_die('De rol kon niet worden aangemaakt.'); }
        $roles = $this->custom_roles();
        $roles[$key] = $label;
        update_option('rex_download_custom_roles', $roles, false);
        wp_safe_redirect(admin_url('admin.php?page=rex-download-roles&created=1')); exit;
    }
    public function roles_admin() {
        if (!current_user_can('manage_options')) { return; }
        echo '<div class="wrap"><h1>Downloadrollen</h1><p>Maak groepen zoals Leden, Technici of Klanten. Een gebruiker kan meerdere downloadrollen hebben. Nieuwe rollen geven alleen leestoegang tot WordPress en geen beheerrechten.</p>';
        if (isset($_GET['created'])) { echo '<div class="notice notice-success"><p>Rol aangemaakt.</p></div>'; }
        echo '<h2>Rol toevoegen</h2><form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';
        wp_nonce_field('rex_manage');
        echo '<input type="hidden" name="action" value="rex_create_role"><table class="form-table"><tr><th><label for="rex-role-label">Naam</label></th><td><input id="rex-role-label" name="role_label" class="regular-text" required maxlength="80" placeholder="Bijvoorbeeld Technici"></td></tr><tr><th><label for="rex-role-slug">Rolcode</label></th><td><input id="rex-role-slug" name="role_slug" class="regular-text" required maxlength="60" pattern="[a-z0-9_\-]+" placeholder="technici"><p class="description">Kleine letters, cijfers, streepjes en underscores. De opgeslagen code krijgt het voorvoegsel rex_.</p></td></tr></table>';
        submit_button('Rol toevoegen');
        echo '</form><h2>Beschikbare WordPress-rollen</h2><table class="widefat striped"><thead><tr><th>Naam</th><th>Code</th><th>Soort</th></tr></thead><tbody>';
        $custom = $this->custom_roles();
        foreach (wp_roles()->get_names() as $key=>$label) {
            echo '<tr><td>'.esc_html(translate_user_role($label)).'</td><td><code>'.esc_html($key).'</code></td><td>'.(isset($custom[$key]) ? 'Eigen downloadrol' : 'Bestaande WordPress-rol').'</td></tr>';
        }
        echo '</tbody></table><p>Ga naar <a href="'.esc_url(admin_url('users.php')).'">Gebruikers</a>, bewerk een gebruiker en vink bij <strong>WP Dares Downloads: extra rollen</strong> de gewenste groepen aan. Selecteer vervolgens deze rollen bij je downloads. Eén overeenkomende rol is voldoende voor toegang.</p></div>';
    }
    public function user_roles($user) {
        if (!current_user_can('manage_options') || !current_user_can('promote_users') || !current_user_can('edit_user', $user->ID)) { return; }
        echo '<h2>WP Dares Downloads: extra rollen</h2><table class="form-table"><tr><th>Downloadgroepen</th><td>';
        wp_nonce_field('rex_user_roles_'.$user->ID, 'rex_roles_nonce');
        echo '<input type="hidden" name="rex_roles_present" value="1">';
        foreach ($this->custom_roles() as $key=>$label) {
            echo '<label style="display:block;margin:8px 0"><input type="checkbox" name="rex_user_roles[]" value="'.esc_attr($key).'" '.checked(in_array($key,(array)$user->roles,true),true,false).'> '.esc_html($label).'</label>';
        }
        echo '<p class="description">Meerdere keuzes zijn mogelijk. Deze selectie beheert alleen de downloadrollen die via WP Dares Downloads zijn aangemaakt. Andere rollen blijven behouden. Maak rollen aan via Downloads → Rollen.</p></td></tr></table>';
    }
    public function save_user_roles($user_id) {
        if (!is_admin() || !isset($_POST['rex_roles_present']) || !current_user_can('manage_options') || !current_user_can('promote_users') || !current_user_can('edit_user', $user_id)) { return; }
        if (!isset($_POST['rex_roles_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['rex_roles_nonce'])), 'rex_user_roles_'.$user_id)) { return; }
        $selected = isset($_POST['rex_user_roles']) && is_array($_POST['rex_user_roles']) ? array_map('sanitize_key', wp_unslash($_POST['rex_user_roles'])) : [];
        $custom = $this->custom_roles();
        $user = get_userdata($user_id);
        if (!$user) { return; }
        // Keep a basic role if the native role selector replaced the user's only custom role.
        $remaining = array_diff((array)$user->roles, array_keys($custom));
        if (!$remaining && !array_intersect($selected, array_keys($custom))) { $user->add_role('subscriber'); }
        foreach ($custom as $key=>$label) {
            if (in_array($key, $selected, true)) { $user->add_role($key); }
            else { $user->remove_role($key); }
        }
    }
    public function storage() {
        // Put this directory outside every publicly served directory.
        $root = realpath(isset($_SERVER['DOCUMENT_ROOT']) ? $_SERVER['DOCUMENT_ROOT'] : ABSPATH);
        if (!$root) { $root = realpath(ABSPATH); }
        $path = defined('REX_DOWNLOADS_DIR') ? REX_DOWNLOADS_DIR : dirname($root).'/rex-private-downloads-'.substr(hash('sha256',ABSPATH),0,12);
        if (!is_dir($path) && !wp_mkdir_p($path)) { return new WP_Error('storage','Privémap kan niet worden gemaakt. Stel REX_DOWNLOADS_DIR in wp-config.php in op een schrijfbare map buiten de webroot.'); }
        $real = realpath($path);
        foreach (array_filter([$root,realpath(ABSPATH)]) as $public) {
            if ($real === $public || strpos(wp_normalize_path($real).'/',trailingslashit(wp_normalize_path($public))) === 0) {
                return new WP_Error('storage','De downloadmap moet buiten de webroot staan.');
            }
        }
        if (!$real || !is_writable($real)) { return new WP_Error('storage','Privémap is niet schrijfbaar.'); }
        @chmod($real,0700);
        return $real;
    }
    public function allowed($id) {
        if (!is_user_logged_in() || !Rex_Downloads_Workflow::available($id)) { return false; }
        if (current_user_can('manage_options')) { return true; }
        if (in_array((int)$id,Rex_Downloads_Workflow::grants(get_current_user_id()),true)) { return true; }
        if (get_post_meta($id,'_rex_access_mode',true) === 'inherit') { return Rex_Downloads_Features::category_access($id); }
        $roles = (array)get_post_meta($id,'_rex_roles',true);
        return in_array('*',$roles,true) || (bool)array_intersect($roles,wp_get_current_user()->roles);
    }
    private function guard() {
        if (!current_user_can('manage_options')) { wp_die('Geen toegang.', '', ['response'=>403]); }
        check_admin_referer('rex_manage');
    }
    private function back() { wp_safe_redirect(admin_url('admin.php?page=rex-downloads')); exit; }
    public function file($id) {
        $dir = $this->storage();
        if (is_wp_error($dir)) { return false; }
        $key = get_post_meta($id,'_rex_key',true);
        if (!is_string($key) || !preg_match('/^[a-f0-9]{48}\.bin$/D',$key)) { return false; }
        $path = realpath($dir.'/'.$key);
        return $path && dirname($path) === $dir && is_file($path) ? $path : false;
    }
    public function save($redirect = true) {
        $this->guard(); Rex_Downloads_Workflow::validate_schedule();
        $id = isset($_POST['id']) ? absint($_POST['id']) : 0;
        if ($id && get_post_type($id) !== 'rex_download') { wp_die('Ongeldig bestand.'); }
        $title = isset($_POST['title']) ? sanitize_text_field(wp_unslash($_POST['title'])) : '';
        if (!$title) { wp_die('Vul een titel in.'); }
        $roles = isset($_POST['roles']) ? array_map(function($r){ return $r === '*' ? '*' : sanitize_key($r); },(array)wp_unslash($_POST['roles'])) : [];
        $roles = array_values(array_intersect($roles,array_merge(['*'],array_keys(wp_roles()->roles))));
        $dir = $this->storage();
        if (is_wp_error($dir)) { wp_die(esc_html($dir->get_error_message())); }
        $new = null;
        if (isset($_FILES['file']) && $_FILES['file']['error'] !== UPLOAD_ERR_NO_FILE) {
            $f = $_FILES['file'];
            if ($f['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name']) || $f['size'] > wp_max_upload_size() || !$f['size']) { wp_die('Upload mislukt of bestand te groot.'); }
            $name = sanitize_file_name($f['name']);
            $ext = strtolower(pathinfo($name,PATHINFO_EXTENSION));
            $extensions = ['pdf','zip','txt','csv','doc','docx','xls','xlsx','ppt','pptx','jpg','jpeg','png','webp','mp3','mp4','stl','bin','hex'];
            if (!in_array($ext,$extensions,true)) { wp_die('Dit bestandstype is niet toegestaan. Gebruik eventueel een ZIP.'); }
            $key = bin2hex(random_bytes(24)).'.bin';
            if (!move_uploaded_file($f['tmp_name'],$dir.'/'.$key)) { wp_die('Bestand kan niet worden opgeslagen.'); }
            @chmod($dir.'/'.$key,0600);
            $new = ['key'=>$key,'name'=>$name,'size'=>filesize($dir.'/'.$key)];
        } elseif (!empty($_POST['media_id'])) {
            $source=Rex_Downloads_Migration::media_source(absint($_POST['media_id']));
            if(is_wp_error($source)){wp_die(esc_html($source->get_error_message()));}
            $name=sanitize_file_name(basename($source));
            $key = bin2hex(random_bytes(24)).'.bin';
            if (!copy($source,$dir.'/'.$key)) { @unlink($dir.'/'.$key); wp_die('Mediabestand kon niet worden gekopieerd.'); }
            @chmod($dir.'/'.$key,0600);
            $new = ['key'=>$key,'name'=>$name,'size'=>filesize($dir.'/'.$key)];
        } elseif (!$id) { wp_die('Kies een bestand uit de mediabibliotheek.'); }
        $old = $id ? $this->file($id) : false;
        $result = wp_insert_post(['ID'=>$id,'post_type'=>'rex_download','post_status'=>'publish','post_title'=>$title,'post_content'=>isset($_POST['description']) ? sanitize_textarea_field(wp_unslash($_POST['description'])) : ''],true);
        if (is_wp_error($result)) { if ($new) { unlink($dir.'/'.$new['key']); } wp_die(esc_html($result->get_error_message())); }
        update_post_meta($result,'_rex_roles',$roles);
        $categories = isset($_POST['categories']) && is_array($_POST['categories']) ? array_map('absint',$_POST['categories']) : [];
        wp_set_object_terms($result,$categories,'rex_download_category');
        if ($new) {
            if ($old) { do_action('rex_download_replacing',$result,$old); }
            foreach ($new as $k=>$v) { update_post_meta($result,'_rex_'.$k,$v); }
        }
        do_action('rex_download_saved',$result,(bool)$new,(bool)$id);
        if ($redirect) { $this->back(); }
        return $result;
    }
    public function delete() {
        $this->guard(); $id = absint($_POST['id'] ?? 0);
        if (get_post_type($id) !== 'rex_download') { wp_die('Ongeldig bestand.'); }
        if (!Rex_Downloads_Workflow::trash_file($id)) { wp_die('Bestand kon niet naar de prullenbak.'); }
        $this->back();
    }
    public function download() {
        $id = absint($_GET['id'] ?? 0);
        if (get_post_type($id) !== 'rex_download' || get_post_status($id) !== 'publish' || !$this->allowed($id)) { wp_die('Je hebt geen toegang tot dit bestand.','Geen toegang',['response'=>403]); }
        check_admin_referer('rex_download_'.$id);
        $file = $this->file($id);
        if (!$file || !($handle = fopen($file,'rb'))) { wp_die('Bestand niet beschikbaar.','',['response'=>404]); }
        $name = get_post_meta($id,'_rex_name',true);
        while (ob_get_level()) { ob_end_clean(); }
        nocache_headers();
        header('Content-Type: application/octet-stream');
        header('X-Content-Type-Options: nosniff');
        header('Content-Disposition: attachment; filename="download.'.sanitize_key(pathinfo($name,PATHINFO_EXTENSION)).'"; filename*=UTF-8\'\''.rawurlencode($name));
        header('Content-Length: '.filesize($file));
        do_action('rex_download_served',$id,'download');
        session_write_close();
        while (!feof($handle)) { echo fread($handle,1024*1024); flush(); if (connection_aborted()) { break; } }
        fclose($handle); exit;
    }
    public function admin() {
        if (!current_user_can('manage_options')) { return; }
        $edit = absint($_GET['edit'] ?? 0);
        if ($edit && get_post_type($edit) !== 'rex_download') { $edit = 0; }
        $post = $edit ? get_post($edit) : null;
        $selected = $edit ? (array)get_post_meta($edit,'_rex_roles',true) : ['*'];
        $page = sanitize_key($_GET['page'] ?? 'rex-downloads');
        $heading = $page === 'rex-download-new' ? 'Nieuw bestand' : ($page === 'rex-download-all' ? 'Alle Downloads' : 'Mijn Downloads');
        echo '<div class="wrap"><h1>'.esc_html($heading).'</h1><p>Plaats <code>[rex_downloads]</code> op een pagina. Rollen worden rechtstreeks uit WordPress gehaald.</p>';
        $storage = $this->storage();
        if (is_wp_error($storage)) { echo '<div class="notice notice-error"><p>'.esc_html($storage->get_error_message()).'</p></div>'; }
        if (isset($_GET['imported'])) { echo '<div class="notice notice-success"><p>'.absint($_GET['imported']).' bestanden toegevoegd.</p></div>'; }
        echo '<h2>'.($edit ? 'Download bewerken' : 'Nieuwe download').'</h2><form method="post" enctype="multipart/form-data" action="'.esc_url(admin_url('admin-post.php')).'">';
        wp_nonce_field('rex_manage');
        echo '<input type="hidden" name="action" value="rex_save"><input type="hidden" name="id" value="'.$edit.'"><table class="form-table"><tr><th>Titel</th><td><input class="regular-text" name="title" id="rex-download-title" required value="'.esc_attr($post ? $post->post_title : '').'"></td></tr><tr><th>Beschrijving</th><td><textarea class="large-text" rows="3" name="description">'.esc_textarea($post ? $post->post_content : '').'</textarea></td></tr><tr><th>Bestand</th><td>';
        echo '<input type="hidden" name="media_id" id="rex-media-id" value="0"><input type="hidden" name="media_ids" id="rex-media-ids" value=""><input type="hidden" id="rex-edit-id" value="'.$edit.'"><p><button type="button" class="button" id="rex-media-select">Kies uit mediabibliotheek</button> <span id="rex-media-name"></span> <button type="button" class="button" id="rex-media-clear">Selectie wissen</button></p><p class="description">Selecteer een bestaand mediabestand. Bij bewerken alleen kiezen om het bestand te vervangen. De beveiligde kopie staat apart; het oorspronkelijke mediabestand behoudt zijn openbare URL.</p>';
        echo '<p><label><input type="checkbox" id="rex-use-filename" '.checked(!$edit,true,false).'> Bestandsnaam overnemen als downloadnaam</label></p><p class="description">Bij het selecteren wordt de volledige bestandsnaam inclusief extensie ingevuld bij Titel. Je kunt de titel daarna zelf aanpassen. Vink uit om je bestaande titel te behouden.</p>';

        echo '</td></tr><tr><th>Categorieën</th><td>';
        $assigned = $edit ? wp_get_object_terms($edit,'rex_download_category',['fields'=>'ids']) : [];
        if (is_wp_error($assigned)) { $assigned = []; }
        foreach ($this->category_tree() as $row) {
            $category = $row['term'];
            echo '<label style="display:block;margin:8px 0;padding-left:'.(int)($row['depth']*22).'px"><input type="checkbox" name="categories[]" value="'.(int)$category->term_id.'" '.checked(in_array($category->term_id,$assigned),true,false).'> '.($row['depth'] ? '↳ ' : '').esc_html($category->name).'</label>';
        }
        echo '<p><a href="'.esc_url(admin_url('admin.php?page=rex-download-categories')).'">Categorie toevoegen</a></p></td></tr><tr><th>Toegang</th><td>';
        foreach (array_merge(['*'=>'Alle ingelogde gebruikers'],wp_roles()->get_names()) as $key=>$label) {
            echo '<label style="display:block;margin:8px 0"><input type="checkbox" name="roles[]" value="'.esc_attr($key).'" '.checked(in_array($key,$selected,true),true,false).'> '.esc_html(translate_user_role($label)).'</label>';
        }
        do_action('rex_download_extra_fields',$edit);
        do_action('rex_download_workflow_fields',$edit);
        echo '<p>Beheerders hebben altijd toegang. Geen vinkjes: alleen beheerders. Rollen zijn exacte keuzes; hogere rollen krijgen niet automatisch toegang.</p></td></tr></table>';
        submit_button('Download opslaan'); echo '</form>'; do_action('rex_bulk_manager'); echo '<h2>Bestanden</h2><table class="widefat striped"><thead><tr><th>Titel</th><th>Bestand</th><th>Toegang</th><th>Acties</th></tr></thead><tbody>';
        $query = ['post_type'=>'rex_download','numberposts'=>-1];
        if ($page === 'rex-downloads') { $query['author'] = get_current_user_id(); }
        if ($page === 'rex-download-new') { $query['post__in'] = [0]; }
        foreach (get_posts($query) as $item) {
            $roles = (array)get_post_meta($item->ID,'_rex_roles',true);
            $labels = get_post_meta($item->ID,'_rex_access_mode',true)==='inherit' ? ['Rollen van de categorie'] : array_map(function($r){ return $r === '*' ? 'Alle ingelogde gebruikers' : translate_user_role(wp_roles()->get_names()[$r] ?? $r); },$roles);
            echo '<tr><td>'.esc_html($item->post_title).'</td><td>'.esc_html(get_post_meta($item->ID,'_rex_name',true)).'</td><td>'.esc_html(implode(', ',$labels) ?: 'Alleen beheerders').'</td><td><a href="'.esc_url(admin_url('admin.php?page=rex-downloads&edit='.$item->ID)).'">Bewerken</a><form style="display:inline;margin-left:15px" method="post" action="'.esc_url(admin_url('admin-post.php')).'" onsubmit="return confirm(\'Bestand naar de prullenbak verplaatsen?\')">';
            wp_nonce_field('rex_manage'); echo '<input type="hidden" name="action" value="rex_delete"><input type="hidden" name="id" value="'.$item->ID.'"><button class="button-link-delete">Verwijderen</button></form></td></tr>';
        }
        echo '</tbody></table></div>';
    }
    public function shortcode() {
        ob_start();
        wp_enqueue_script('rex-download-frontend',plugins_url('frontend.js',__FILE__),[], '3.0.0',true);
        $settings = Rex_Downloads_Features::settings();
        echo '<section data-workflow-nonce="'.esc_attr(wp_create_nonce('rex_workflow')).'" data-ajax-url="'.esc_url(admin_url('admin-ajax.php')).'" data-favorite-nonce="'.esc_attr(wp_create_nonce('rex_favorite')).'" class="rex-downloads rex-density-'.esc_attr($settings['density']).'" style="--rex-accent:'.esc_attr($settings['accent']).'">'.Rex_Downloads_Frontend::styles().Rex_Downloads_Features::styles().Rex_Downloads_Workflow::styles().'<h2>Downloads</h2>';
        if (!is_user_logged_in()) {
            echo '<p>Log in met je WordPress-account om je bestanden te bekijken.</p>';
            wp_login_form(['redirect'=>get_permalink(),'label_username'=>'Gebruikersnaam of e-mailadres','label_password'=>'Wachtwoord','label_remember'=>'Ingelogd blijven','label_log_in'=>'Inloggen','id_username'=>'rex-user','id_password'=>'rex-pass','id_remember'=>'rex-remember','id_submit'=>'rex-submit']);
            echo '<a href="'.esc_url(wp_lostpassword_url(get_permalink())).'">Wachtwoord vergeten?</a>';
        } else {
            echo '<p class="rex-account">Ingelogd als '.esc_html(wp_get_current_user()->display_name).' · <a href="'.esc_url(wp_logout_url(get_permalink())).'">Uitloggen</a></p>';
            Rex_Downloads_Features::toolbar($this->category_tree());do_action('rex_download_subscriptions');
            Rex_Downloads_Frontend::files($this->category_tree(), Rex_Downloads_Workflow::ordered_files(get_posts(['post_type'=>'rex_download','post_status'=>'publish','numberposts'=>-1,'orderby'=>'title','order'=>'ASC'])), function($id){ return $this->allowed($id); });
        }
        if(is_user_logged_in()){echo Rex_Downloads_Workflow::pdf_dialog();}
        echo '</section>'; return ob_get_clean();
    }
}
require_once __DIR__.'/workflow.php';
require_once __DIR__.'/features.php';
require_once __DIR__.'/frontend.php';
$rex_downloads_core = new Rex_Downloads();
new Rex_Downloads_Features($rex_downloads_core);
new Rex_Downloads_Workflow($rex_downloads_core);
add_action('deactivate_'.plugin_basename(__FILE__),function(){wp_clear_scheduled_hook('rex_download_prune');wp_clear_scheduled_hook('rex_download_notify');});

require_once __DIR__.'/github-updater.php';
new Rex_Downloads_GitHub_Updater(__FILE__, '3.0.0');
