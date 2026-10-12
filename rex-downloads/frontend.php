<?php
if (!defined('ABSPATH')) { exit; }
final class Rex_Downloads_Frontend {
    public static function files($rows,$posts,$access) {
        $children = []; $files = []; $uncategorized = []; $known = [];
        foreach ($rows as $row) { $known[(int)$row['term']->term_id] = true; }
        foreach ($rows as $row) {
            $term = $row['term'];
            $parent = isset($known[(int)$term->parent]) ? (int)$term->parent : 0;
            $children[$parent][] = $term;
        }
        foreach ($posts as $post) {
            if (!$access($post->ID)) { continue; }
            $ids = wp_get_object_terms($post->ID,'rex_download_category',['fields'=>'ids']);
            $assigned = false;
            if (!is_wp_error($ids)) {
                foreach ($ids as $id) { if (isset($known[(int)$id])) { $files[(int)$id][] = $post; $assigned = true; } }
            }
            if (!$assigned) { $uncategorized[] = $post; }
        }
        $seen = [];
        $render = function($parent) use (&$render,&$children,&$seen,$files) {
            foreach ($children[$parent] ?? [] as $term) {
                $id = (int)$term->term_id;
                if (isset($seen[$id])) { continue; }
                $seen[$id] = true;
                echo '<details class="rex-folder" data-category="'.$id.'" '.(Rex_Downloads_Features::settings()['collapsed']?'':'open').'><summary><span class="rex-folder-icon" aria-hidden="true">▰</span><span>'.esc_html($term->name).'</span></summary><div class="rex-folder-content">';
                Rex_Downloads_Workflow::category_actions($id);
                foreach (Rex_Downloads_Workflow::ordered_files($files[$id] ?? [],$id) as $post) { self::file_row($post); }
                $render($id);
                if (empty($files[$id]) && empty($children[$id])) { echo '<p class="rex-empty">Geen downloads beschikbaar in deze categorie.</p>'; }
                echo '</div></details>';
            }
        };
        echo '<div class="rex-tree">';
        $render(0);
        // Retain visibility if imported data contains orphaned or cyclic terms.
        foreach ($rows as $row) {
            $id = (int)$row['term']->term_id;
            if (!isset($seen[$id])) {
                echo '<details class="rex-folder" data-category="'.$id.'" '.(Rex_Downloads_Features::settings()['collapsed']?'':'open').'><summary>'.esc_html($row['term']->name).'</summary><div class="rex-folder-content">';
                $seen[$id] = true;
                foreach (Rex_Downloads_Workflow::ordered_files($files[$id] ?? [],$id) as $post) { self::file_row($post); }
                $render($id); echo '</div></details>';
            }
        }
        if ($uncategorized) {
            echo '<details class="rex-folder" data-category="0" '.(Rex_Downloads_Features::settings()['collapsed']?'':'open').'><summary><span class="rex-folder-icon" aria-hidden="true">▰</span><span>Overige bestanden</span></summary><div class="rex-folder-content">';
            foreach ($uncategorized as $post) { self::file_row($post); }
            echo '</div></details>';
        }
        if (!$rows && !$uncategorized) { echo '<p class="rex-empty">Er zijn nog geen downloads beschikbaar voor jouw account.</p>'; }
        echo '</div>';
    }
    private static function file_row($post) {
        $url = wp_nonce_url(add_query_arg(['action'=>'rex_download','id'=>$post->ID],admin_url('admin-post.php')),'rex_download_'.$post->ID);
        $filename = get_post_meta($post->ID,'_rex_name',true);
        $extension = strtoupper(pathinfo($filename,PATHINFO_EXTENSION));
        $size = size_format((int)get_post_meta($post->ID,'_rex_size',true));
        $favorite = in_array((int)$post->ID,Rex_Downloads_Features::favorites(),true);
        $version = get_post_meta($post->ID,'_rex_version',true);
        $created = (int)get_post_meta($post->ID,'_rex_created_at',true);
        if (!$created && !empty($post->post_date_gmt)) { $created = strtotime($post->post_date_gmt.' UTC'); }
        $updated = (int)get_post_meta($post->ID,'_rex_updated_at',true);
        $days = Rex_Downloads_Features::settings()['badge_days']*86400;
        echo '<div class="rex-file-group" data-id="'.(int)$post->ID.'" data-type="'.esc_attr(strtolower($extension)).'" data-favorite="'.($favorite?'1':'0').'" data-search="'.esc_attr($post->post_title.' '.$filename.' '.$post->post_content.' '.$version).'"><a class="rex-file" href="'.esc_url($url).'"><span class="rex-file-icon" aria-hidden="true">↓</span><span class="rex-file-text"><span class="rex-file-title">'.esc_html($post->post_title).'</span>';
        if ($post->post_content) { echo '<span class="rex-file-description">'.esc_html($post->post_content).'</span>'; }
        echo '</span><span class="rex-file-size">'.esc_html($size).'</span><span class="rex-file-type">'.esc_html($extension ?: 'FILE').'</span><span class="rex-sr-only"> — bestand downloaden</span></a><div class="rex-file-actions">';
        if($created && time()-$created<$days){echo '<span class="rex-badge">Nieuw</span>';}
        elseif($updated && time()-$updated<$days){echo '<span class="rex-badge rex-badge-updated">Bijgewerkt</span>';}
        if($version){echo '<span class="rex-version">v'.esc_html($version).'</span>';}
        if($updated||$created){echo '<span class="rex-version">'.esc_html(wp_date('d-m-Y',$updated?:$created)).'</span>';}
        if(is_user_logged_in()){echo '<button type="button" class="rex-favorite" data-id="'.(int)$post->ID.'" aria-pressed="'.($favorite?'true':'false').'">'.($favorite?'★':'☆').' Favoriet</button>';}
        if($extension==='PDF'){$preview=wp_nonce_url(add_query_arg(['action'=>'rex_preview','id'=>$post->ID],admin_url('admin-post.php')),'rex_preview_'.$post->ID);echo '<a class="rex-preview" data-download-url="'.esc_url($url).'" href="'.esc_url($preview).'" target="_blank" rel="noopener">PDF bekijken ↗</a>';}
        Rex_Downloads_Workflow::file_actions($post->ID);
        echo '</div>';
        $history=get_post_meta($post->ID,'_rex_history',true);
        $notes=get_post_meta($post->ID,'_rex_changelog',true);
        if((is_array($history)&&$history)||$notes){echo '<details class="rex-changelog"><summary>Wijzigingslog</summary>';if(is_array($history)&&$history){foreach(array_reverse($history) as $entry){echo '<p><strong>'.esc_html(($entry['version']?:'Zonder versie').' · '.wp_date('d-m-Y',$entry['time'])).'</strong><br>'.esc_html($entry['notes']).'</p>';}}else{echo '<p>'.esc_html($notes).'</p>';}echo '</details>';}
        echo '</div>';
    }
    public static function styles() {
        return '<style>
.rex-downloads{color:#163b60;background:#fff;font:15px/1.5 system-ui,-apple-system,Segoe UI,sans-serif;padding:20px;border:1px solid #d6dee6;border-radius:5px;box-sizing:border-box}
.rex-downloads *{box-sizing:border-box}.rex-downloads h2{font-size:22px;color:#123e68;border-bottom:2px solid #164b7b;padding-bottom:10px;margin:0 0 15px}.rex-downloads .rex-account{font-size:13px;margin:0 0 18px;color:#536577}.rex-downloads a{color:#17639e}.rex-downloads .rex-folder{margin:12px 0;border:1px solid #cbd5df;background:#f7f9fb;border-radius:3px;overflow:hidden}.rex-downloads .rex-folder summary{display:list-item;cursor:pointer;padding:10px 12px;list-style-position:inside;color:#173e61;font-weight:600;font-size:14px}.rex-downloads summary::marker{color:#2873ad}.rex-downloads summary:focus-visible,.rex-downloads .rex-file:focus-visible{outline:3px solid #1d78b8;outline-offset:-3px}.rex-downloads .rex-folder-icon{color:#2873ad;margin-right:7px}.rex-downloads .rex-folder-content{margin:0 12px 12px 20px;padding-left:12px;border-left:1px dashed #aebdcc}.rex-downloads .rex-folder-content>.rex-folder{background:#fff}.rex-downloads a.rex-file{display:flex;align-items:center;gap:9px;padding:9px 10px;margin:7px 0;border:1px solid #d5dde5;background:#fff;border-radius:2px;text-decoration:none;transition:background .15s,border-color .15s}.rex-downloads a.rex-file:hover{background:#eff7ff;border-color:#82acd0}.rex-downloads .rex-file-icon{color:#2873ad;font-weight:700;font-size:18px;flex-shrink:0}.rex-downloads .rex-file-text{flex:1;min-width:0}.rex-downloads .rex-file-title{display:block;overflow-wrap:anywhere;font-size:14px}.rex-downloads .rex-file-description{display:block;font-size:12px;color:#66798a;margin-top:2px;white-space:pre-line}.rex-downloads .rex-file-size{color:#72808d;font-size:11px;white-space:nowrap}.rex-downloads .rex-file-type{border:1px solid #d4dce4;border-radius:3px;background:#f4f6f8;color:#637486;font-size:10px;padding:2px 5px;min-width:30px;text-align:center}.rex-downloads .rex-empty{font-size:12px;color:#738292;margin:10px 0}.rex-downloads input{max-width:100%;padding:9px;border:1px solid #cbd5df;border-radius:4px}.rex-downloads input[type=submit]{background:#17639e;color:#fff;cursor:pointer}.rex-downloads .rex-sr-only{position:absolute;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;clip:rect(0,0,0,0);white-space:nowrap;border:0}@media(max-width:600px){.rex-downloads{padding:12px}.rex-downloads .rex-folder-content{margin-left:9px;margin-right:7px;padding-left:7px}.rex-downloads .rex-folder summary{padding:9px 8px}.rex-downloads .rex-file-size{display:none}.rex-downloads a.rex-file{gap:6px;padding:8px 6px}}
</style>';
    }
}
