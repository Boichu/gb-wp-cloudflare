<?php
/**
 * Plugin Name: GB - WP - Cloudflare
 * Description: Purge automatique Cloudflare via l’API officielle (api.cloudflare.com). Ajoute aussi un bouton manuel dans l’admin pour vider le cache.
 * Version: 1.0.2
 * Author: Gaétan Boishue
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Configuration – remplacez par vos vraies valeurs
 */
// Charger les valeurs depuis les options WordPress
define('GB_CF_ZONE_ID', get_option('gb_cf_zone_id', ''));
define('GB_CF_API_TOKEN', get_option('gb_cf_api_token', ''));

// Ajout de la page d’options dans l’admin
add_action('admin_menu', function() {
    add_options_page(
        'Cloudflare – Configuration',
        'Cloudflare',
        'manage_options',
        'gb-cf-settings',
        'gb_cf_settings_page'
    );
});

function gb_cf_settings_page() {
    if (!current_user_can('manage_options')) {
        wp_die('Tu n’as pas la permission pour ça.');
    }

    if (isset($_POST['gb_cf_save_settings'])) {
        update_option('gb_cf_zone_id', sanitize_text_field($_POST['gb_cf_zone_id']));
        update_option('gb_cf_api_token', sanitize_text_field($_POST['gb_cf_api_token']));
        echo '<div class="notice notice-success"><p>Configuration enregistrée ✅</p></div>';
    }

    $zone_id = esc_attr(get_option('gb_cf_zone_id', ''));
    $api_token = esc_attr(get_option('gb_cf_api_token', ''));

    ?>
    <div class="wrap">
        <h1>Configuration Cloudflare</h1>
        <p>
            <strong>Comment obtenir vos codes Cloudflare ?</strong><br>
            <ul>
            <li>
                <b>Zone ID :</b> 
                <a href="https://dash.cloudflare.com/?zone=overview" target="_blank">Connectez-vous à votre tableau de bord Cloudflare</a>, sélectionnez votre site, puis copiez le <b>Zone ID</b> affiché dans la section “Overview”.
            </li>
            <li>
                <b>API Token :</b> 
                <a href="https://dash.cloudflare.com/profile/api-tokens" target="_blank">Générez un API Token ici</a>.<br>
                Cliquez sur “Create Token”, choisissez le modèle “Edit Cloudflare Zone”, puis autorisez-le sur la zone de votre site.<br>
                Copiez le token généré et collez-le ici.
            </li>
            </ul>
        </p>
        <form method="post">
            <table class="form-table">
                <tr>
                    <th scope="row"><label for="gb_cf_zone_id">Zone ID</label></th>
                    <td><input type="text" id="gb_cf_zone_id" name="gb_cf_zone_id" value="<?= $zone_id ?>" class="regular-text" /></td>
                </tr>
                <tr>
                    <th scope="row"><label for="gb_cf_api_token">API Token</label></th>
                    <td><input type="text" id="gb_cf_api_token" name="gb_cf_api_token" value="<?= $api_token ?>" class="regular-text" /></td>
                </tr>
            </table>
            <?php submit_button('Enregistrer', 'primary', 'gb_cf_save_settings'); ?>
        </form>

        <hr>
        <h2>Tester la connexion</h2>
        <p>
            <button type="button" id="gb_cf_test_connection" class="button button-secondary">Tester la connexion API</button>
            <span id="gb_cf_test_result" style="margin-left: 10px;"></span>
        </p>

        <script>
        document.getElementById('gb_cf_test_connection').addEventListener('click', function() {
            var btn = this;
            var result = document.getElementById('gb_cf_test_result');
            btn.disabled = true;
            result.innerHTML = 'Test en cours...';
            result.style.color = '#666';

            fetch(ajaxurl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'action=gb_cf_test_connection&nonce=<?= wp_create_nonce('gb_cf_test_connection') ?>'
            })
            .then(response => response.json())
            .then(data => {
                btn.disabled = false;
                if (data.success) {
                    result.innerHTML = '✅ ' + data.data.message;
                    result.style.color = 'green';
                } else {
                    result.innerHTML = '❌ ' + data.data.message;
                    result.style.color = 'red';
                }
            })
            .catch(error => {
                btn.disabled = false;
                result.innerHTML = '❌ Erreur: ' + error;
                result.style.color = 'red';
            });
        });
        </script>
    </div>
    <?php
}

/**
 * AJAX handler pour tester la connexion API Cloudflare
 */
add_action('wp_ajax_gb_cf_test_connection', function() {
    check_ajax_referer('gb_cf_test_connection', 'nonce');

    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => 'Permission refusée']);
    }

    $zone_id = get_option('gb_cf_zone_id', '');
    $api_token = get_option('gb_cf_api_token', '');

    if (empty($zone_id) || empty($api_token)) {
        wp_send_json_error(['message' => 'Zone ID ou API Token manquant']);
    }

    // 1. Test du token
    $response = wp_remote_get('https://api.cloudflare.com/client/v4/user/tokens/verify', [
        'headers' => [
            'Authorization' => 'Bearer ' . $api_token,
            'Content-Type'  => 'application/json',
        ],
        'timeout' => 15,
    ]);

    if (is_wp_error($response)) {
        wp_send_json_error(['message' => 'Erreur HTTP: ' . $response->get_error_message()]);
    }

    $body = json_decode(wp_remote_retrieve_body($response), true);

    if (empty($body['success']) || $body['success'] !== true) {
        $error_msg = $body['errors'][0]['message'] ?? 'Erreur inconnue';
        wp_send_json_error(['message' => 'Token invalide: ' . $error_msg]);
    }

    $status = $body['result']['status'] ?? 'unknown';
    if ($status !== 'active') {
        wp_send_json_error(['message' => 'Token trouvé mais statut: ' . $status]);
    }

    // 2. Test du Zone ID
    $zone_response = wp_remote_get('https://api.cloudflare.com/client/v4/zones/' . $zone_id, [
        'headers' => [
            'Authorization' => 'Bearer ' . $api_token,
            'Content-Type'  => 'application/json',
        ],
        'timeout' => 15,
    ]);

    if (is_wp_error($zone_response)) {
        wp_send_json_error(['message' => 'Token OK, mais erreur HTTP sur Zone: ' . $zone_response->get_error_message()]);
    }

    $zone_body = json_decode(wp_remote_retrieve_body($zone_response), true);

    if (empty($zone_body['success']) || $zone_body['success'] !== true) {
        $error_msg = $zone_body['errors'][0]['message'] ?? 'Zone ID invalide ou non autorisé';
        wp_send_json_error(['message' => 'Token OK, mais Zone ID invalide: ' . $error_msg]);
    }

    $zone_name = $zone_body['result']['name'] ?? 'inconnu';
    wp_send_json_success(['message' => 'Connexion réussie ! Zone: ' . $zone_name]);
});

/**
 * Fonction générique de purge Cloudflare
 *
 * @param array $data – Exemple : ['purge_everything' => true] ou ['files' => ['https://...']]
 * @return bool – true si succès, false sinon
 */
function gb_cf_perform_purge($data) {
    $url = "https://api.cloudflare.com/client/v4/zones/" . GB_CF_ZONE_ID . "/purge_cache";

    $response = wp_remote_post($url, [
        'headers' => [
            'Authorization' => 'Bearer ' . GB_CF_API_TOKEN,
            'Content-Type'  => 'application/json',
        ],
        'body' => wp_json_encode($data),
        'timeout' => 20,
    ]);

    if (is_wp_error($response)) {
        error_log('gb_wp_cloudflare: HTTP error ' . $response->get_error_message());
        return false;
    }

    $body = json_decode(wp_remote_retrieve_body($response), true);
    if (!empty($body['success']) && $body['success'] === true) {
        return true;
    }

    error_log('gb_wp_cloudflare: API error ' . print_r($body, true));
    return false;
}

/**
 * Purge auto Cloudflare quand un contenu est mis à jour (posts / produits publiés)
 */
add_action('save_post', function($post_ID, $post, $update) {
    // Types de post à purger : articles, pages, produits
    $allowed_types = ['post', 'page', 'product'];
    if (!in_array($post->post_type, $allowed_types, true)) {
        return;
    }

    if ($post->post_status !== 'publish') {
        return;
    }

    $urls_to_purge = [];

    // Purge l’URL du post
    $permalink = get_permalink($post_ID);
    if ($permalink) {
        $urls_to_purge[] = $permalink;
    }

    // Purge la catégorie d’article si applicable
    if ($post->post_type === 'post') {
        $categories = get_the_category($post_ID);
        foreach ($categories as $cat) {
            $cat_link = get_category_link($cat->term_id);
            if ($cat_link) {
                $urls_to_purge[] = $cat_link;
            }
        }
    }

    // Purge la catégorie de produit si applicable (WooCommerce)
    if ($post->post_type === 'product') {
        $terms = get_the_terms($post_ID, 'product_cat');
        if ($terms && !is_wp_error($terms)) {
            foreach ($terms as $term) {
                $term_link = get_term_link($term, 'product_cat');
                if ($term_link && !is_wp_error($term_link)) {
                    $urls_to_purge[] = $term_link;
                }
            }
        }
    }

    if (!empty($urls_to_purge)) {
        gb_cf_perform_purge([
            'files' => $urls_to_purge
        ]);
    }
}, 20, 3);

/**
 * Ajout d'un menu admin avec lien direct de purge
 */
add_action('admin_menu', function() {
    add_menu_page(
        'Purger Cloudflare',
        'Purger Cloudflare',
        'manage_options',
        'gb-cf-purge',
        'gb_cf_purge_admin_page',
        'dashicons-cloud',
        75
    );
});

/**
 * Intercepter le clic sur le menu pour purger directement
 */
add_action('admin_init', function() {
    if (!isset($_GET['page']) || $_GET['page'] !== 'gb-cf-purge') {
        return;
    }

    if (!current_user_can('manage_options')) {
        return;
    }

    // Vérifier si les paramètres sont configurés
    $zone_id = get_option('gb_cf_zone_id', '');
    $api_token = get_option('gb_cf_api_token', '');

    if (empty($zone_id) || empty($api_token)) {
        // Rediriger vers la page de configuration
        wp_redirect(admin_url('options-general.php?page=gb-cf-settings&notice=config_missing'));
        exit;
    }

    // Purger le cache directement
    $ok = gb_cf_perform_purge(['purge_everything' => true]);

    // Rediriger vers la page d'origine avec un message
    $redirect = wp_get_referer() ?: admin_url();
    $redirect = add_query_arg('gb_cf_purged', $ok ? 'success' : 'error', $redirect);
    wp_redirect($redirect);
    exit;
});

/**
 * Afficher les notices après la purge
 */
add_action('admin_notices', function() {
    if (isset($_GET['gb_cf_purged'])) {
        if ($_GET['gb_cf_purged'] === 'success') {
            echo '<div class="notice notice-success is-dismissible"><p>Cache Cloudflare purgé avec succès ✅</p></div>';
        } else {
            echo '<div class="notice notice-error is-dismissible"><p>Erreur lors de la purge du cache Cloudflare ❌</p></div>';
        }
    }
    if (isset($_GET['notice']) && $_GET['notice'] === 'config_missing') {
        echo '<div class="notice notice-warning is-dismissible"><p>Veuillez configurer Zone ID et API Token avant de purger le cache.</p></div>';
    }
});

add_action('admin_bar_menu', function($wp_admin_bar) {
    if (!current_user_can('manage_options')) {
        return;
    }
    $wp_admin_bar->add_node([
        'id'    => 'gb_cf_purge_topbar',
        'title' => 'Purger Cloudflare',
        'href'  => admin_url('admin.php?page=gb-cf-purge'),
        'meta'  => [
            'class' => 'gb-cf-purge-topbar',
            'title' => 'Purger tout le cache Cloudflare'
        ]
    ]);
}, 100);

/**
 * Page d’admin avec bouton de purge manuelle
 */
function gb_cf_purge_admin_page() {
    if (!current_user_can('manage_options')) {
        wp_die('Tu n’as pas la permission pour ça.');
    }

    if (isset($_POST['gb_cf_purge_btn'])) {
        $ok = gb_cf_perform_purge(['purge_everything' => true]);
        if ($ok) {
            echo '<div class="notice notice-success"><p>Cache Cloudflare purgé avec succès ✅</p></div>';
        } else {
            echo '<div class="notice notice-error"><p>Erreur lors de la purge du cache ❌ (voir logs).</p></div>';
        }
    }

    echo '<div class="wrap">';
    echo '<h1>Purger Cloudflare</h1>';
    echo '<form method="post">';
    submit_button('Purger tout le cache Cloudflare', 'primary', 'gb_cf_purge_btn');
    echo '</form></div>';
}

/**
 * Vérifie les mises à jour du plugin via GitHub
 */
add_filter('pre_set_site_transient_update_plugins', function($transient) {
    if (empty($transient->checked)) {
        return $transient;
    }

    $plugin_slug = 'gb_wp_cloudflare';
    $plugin_file = 'gb_wp_cloudflare/gb_wp_cloudflare.php';
    $github_api_url = 'https://api.github.com/repos/gaetanboishue/gb_wp_cloudflare/releases/latest';

    $response = wp_remote_get($github_api_url, [
        'headers' => [
            'Accept' => 'application/vnd.github.v3+json',
            'User-Agent' => 'WordPress/' . get_bloginfo('version'),
        ],
        'timeout' => 15,
    ]);

    if (is_wp_error($response)) {
        return $transient;
    }

    $body = json_decode(wp_remote_retrieve_body($response), true);
    if (empty($body['tag_name'])) {
        return $transient;
    }

    $latest_version = ltrim($body['tag_name'], 'v');
    $current_version = get_plugin_data(__FILE__)['Version'];

    if (version_compare($latest_version, $current_version, '>')) {
        $download_url = $body['zipball_url'];
        $transient->response[$plugin_file] = (object)[
            'slug'        => $plugin_slug,
            'plugin'      => $plugin_file,
            'new_version' => $latest_version,
            'url'         => $body['html_url'],
            'package'     => $download_url,
        ];
    }

    return $transient;
});

/**
 * Affiche les infos de la mise à jour dans la popup
 */
add_filter('plugins_api', function($res, $action, $args) {
    if ($action !== 'plugin_information' || empty($args->slug) || $args->slug !== 'gb_wp_cloudflare') {
        return $res;
    }

    $github_api_url = 'https://api.github.com/repos/gaetanboishue/gb_wp_cloudflare/releases/latest';
    $response = wp_remote_get($github_api_url, [
        'headers' => [
            'Accept' => 'application/vnd.github.v3+json',
            'User-Agent' => 'WordPress/' . get_bloginfo('version'),
        ],
        'timeout' => 15,
    ]);

    if (is_wp_error($response)) {
        return $res;
    }

    $body = json_decode(wp_remote_retrieve_body($response), true);
    if (empty($body['tag_name'])) {
        return $res;
    }

    $info = (object)[
        'name'          => 'gb_wp_cloudflare',
        'slug'          => 'gb_wp_cloudflare',
        'version'       => ltrim($body['tag_name'], 'v'),
        'author'        => '<a href="https://github.com/gaetanboishue">Gaétan Boishue</a>',
        'homepage'      => $body['html_url'],
        'download_link' => $body['zipball_url'],
        'sections'      => [
            'description' => !empty($body['body']) ? nl2br($body['body']) : '',
            'changelog'   => !empty($body['body']) ? nl2br($body['body']) : '',
        ],
    ];

    return $info;
}, 10, 3);