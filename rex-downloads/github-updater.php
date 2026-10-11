<?php
if (!defined('ABSPATH')) { exit; }
final class Rex_Downloads_GitHub_Updater {
    const REPOSITORY = 'spikerm/wp-downloads';
    const URI = 'https://github.com/spikerm/wp-downloads';
    const CACHE = 'rex_downloads_github_release';
    private $file;
    private $version;
    public function __construct($file, $version) {
        $this->file = plugin_basename($file);
        $this->version = $version;
        add_filter('update_plugins_github.com', [$this,'update'], 10, 4);
        add_filter('plugins_api', [$this,'information'], 20, 3);
        add_action('admin_menu', function(){ add_submenu_page('rex-downloads','Pluginupdates','Updates','update_plugins','rex-download-updates',[$this,'admin']); },20);
        add_action('admin_post_rex_check_plugin_updates', [$this,'check']);
        add_action('upgrader_process_complete',function($upgrader,$options){
            if (($options['type'] ?? '') === 'plugin' && ($options['action'] ?? '') === 'update') { delete_site_transient(self::CACHE); }
        },10,2);
    }
    public function release() {
        $cached = get_site_transient(self::CACHE);
        if ($cached !== false) { return is_array($cached) && isset($cached['version']) ? $cached : false; }
        $response = wp_remote_get('https://api.github.com/repos/'.self::REPOSITORY.'/releases/latest',[
            'timeout'=>8,'headers'=>['Accept'=>'application/vnd.github+json','User-Agent'=>'Rex-Downloads/'.$this->version],
        ]);
        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
            set_site_transient(self::CACHE,['error'=>true],5*MINUTE_IN_SECONDS); return false;
        }
        $data = json_decode(wp_remote_retrieve_body($response),true);
        if (!is_array($data) || !empty($data['draft']) || !empty($data['prerelease']) || !preg_match('/^v?(\d+\.\d+\.\d+)$/D',$data['tag_name'] ?? '',$match)) {
            set_site_transient(self::CACHE,['error'=>true],5*MINUTE_IN_SECONDS); return false;
        }
        $package = '';
        foreach ($data['assets'] ?? [] as $asset) {
            $url = $asset['browser_download_url'] ?? '';
            if (($asset['name'] ?? '') === 'rex-downloads.zip' && strpos($url,self::URI.'/releases/download/') === 0 && substr($url,-18) === '/rex-downloads.zip') { $package = esc_url_raw($url); break; }
        }
        if (!$package) { set_site_transient(self::CACHE,['error'=>true],5*MINUTE_IN_SECONDS); return false; }
        $release = ['version'=>$match[1],'package'=>$package,'url'=>self::URI.'/releases','notes'=>sanitize_textarea_field($data['body'] ?? ''),'published'=>sanitize_text_field($data['published_at'] ?? '')];
        set_site_transient(self::CACHE,$release,HOUR_IN_SECONDS);
        return $release;
    }
    public function update($update,$plugin_data,$plugin_file,$locales) {
        if ($plugin_file !== $this->file || ($plugin_data['UpdateURI'] ?? '') !== self::URI) { return $update; }
        $release = $this->release();
        if (!$release) { return $update; }
        return ['id'=>self::URI,'slug'=>'rex-downloads','version'=>$release['version'],'url'=>$release['url'],'package'=>$release['package'],'requires'=>'6.0','requires_php'=>'7.4'];
    }
    public function information($result,$action,$args) {
        if ($action !== 'plugin_information' || ($args->slug ?? '') !== 'rex-downloads') { return $result; }
        $release = $this->release();
        if (!$release) { return $result; }
        return (object)['name'=>'Rex Downloads','slug'=>'rex-downloads','version'=>$release['version'],'author'=>'Rex Markesteijn','homepage'=>self::URI,'requires'=>'6.0','requires_php'=>'7.4','download_link'=>$release['package'],'last_updated'=>$release['published'],'sections'=>['description'=>'Afgeschermde downloads met rollen, categorieën, subcategorieën en mediabibliotheek.','changelog'=>nl2br(esc_html($release['notes']))]];
    }
    public function check() {
        if (!current_user_can('update_plugins')) { wp_die('Geen toegang.','',['response'=>403]); }
        check_admin_referer('rex_check_plugin_updates');
        delete_site_transient(self::CACHE);
        delete_site_transient('update_plugins');
        wp_update_plugins();
        wp_safe_redirect(admin_url('admin.php?page=rex-download-updates')); exit;
    }
    public function admin() {
        if (!current_user_can('update_plugins')) { return; }
        $release = $this->release();
        echo '<div class="wrap"><h1>Rex Downloads — Updates</h1><p>Geïnstalleerde versie: <strong>'.esc_html($this->version).'</strong></p>';
        if (!$release) { echo '<div class="notice notice-warning inline"><p>GitHub-release niet beschikbaar. Controleer je internetverbinding of probeer later opnieuw.</p></div>'; }
        else {
            echo '<p>Nieuwste release: <strong>'.esc_html($release['version']).'</strong></p>';
            if (version_compare($release['version'],$this->version,'>')) { echo '<div class="notice notice-info inline"><p>Er is een update beschikbaar. <a href="'.esc_url(admin_url('plugins.php')).'">Open Plugins om bij te werken</a>.</p></div>'; }
            else { echo '<p>Je gebruikt de nieuwste versie of een nieuwere ontwikkelversie.</p>'; }
            if ($release['notes']) { echo '<h2>Wijzigingen</h2><p>'.nl2br(esc_html($release['notes'])).'</p>'; }
        }
        echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">'; wp_nonce_field('rex_check_plugin_updates');
        echo '<input type="hidden" name="action" value="rex_check_plugin_updates">'; submit_button('Nu controleren');
        echo '</form><p>WordPress controleert automatisch op nieuwe releases. Automatisch installeren kun je inschakelen via de pluginlijst.</p><p><a href="'.esc_url(self::URI).'" target="_blank" rel="noopener noreferrer">GitHub-project openen</a></p></div>';
    }
}
