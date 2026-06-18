<?php
// 1. Admin Sayfası İçeriği (Ferdimen Addons Merkezi)
function ferdimen_addons_merkezi_sayfasi() {
    ?>
    <div class="wrap">
        <h1>Ferdimen Addons Merkezi</h1>
        <p>Yüklü eklentilerinizin güncelliğini buradan kontrol edebilir ve güncelleyebilirsiniz.</p>
        
        <table class="wp-list-table widefat fixed striping health-check-table">
            <thead>
                <tr>
                    <th>Eklenti Adı</th>
                    <th>Mevcut Versiyon</th>
                    <th>Durum / İşlem</th>
                </tr>
            </thead>
            <tbody>
                <?php
                // Sadece kendi belirlediğiniz veya tüm yüklü eklentileri çekebilirsiniz.
                // Örnek olarak aktif eklentileri listeliyoruz:
                $plugins = get_plugins();
                $active_plugins = get_option('active_plugins');

                foreach ($plugins as $plugin_file => $plugin_data) {
                    // Sadece aktif olanları veya isminde 'ferdimen' geçenleri filtrelemek isterseniz burayı düzenleyebilirsiniz
                    $plugin_slug = dirname($plugin_file);
                    ?>
                    <tr id="plugin-row-<?php echo esc_attr(sanitize_title($plugin_slug)); ?>" data-plugin="<?php echo esc_attr($plugin_file); ?>">
                        <td><strong><?php echo esc_html($plugin_data['Name']); ?></strong></td>
                        <td><?php echo esc_html($plugin_data['Version']); ?></td>
                        <td class="action-cell">
                            <button type="button" class="button ferdimen-check-update-btn" data-slug="<?php echo esc_attr($plugin_slug); ?>">
                                Güncellemeleri Denetle
                            </button>
                            <span class="spinner" style="float: none; margin: 0 10px;"></span>
                            <span class="update-message"></span>
                        </td>
                    </tr>
                    <?php
                }
                ?>
            </tbody>
        </table>
    </div>

    <!-- JavaScript Kodlarını Sayfaya Dahil Ediyoruz -->
    <script type="text/javascript">
    jQuery(document).ready(function($) {
        // GÜNCELLEME DENETLEME BUTONU
        $('.ferdimen-check-update-btn').on('click', function(e) {
            e.preventDefault();
            var $btn = $(this);
            var $row = $btn.closest('tr');
            var $spinner = $row.find('.spinner');
            var $msg = $row.find('.update-message');
            var pluginFile = $row.data('plugin');

            $btn.prop('disabled', true);
            $spinner.addClass('is-active');
            $msg.html('');

            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'ferdimen_check_plugin_update',
                    plugin_file: pluginFile,
                    nonce: '<?php echo wp_create_nonce("ferdimen_update_nonce"); ?>'
                },
                success: function(response) {
                    $spinner.removeClass('is-active');
                    $btn.prop('disabled', false);

                    if (response.success) {
                        if (response.data.update_available) {
                            // Güncelleme varsa: "Güncelleme Yap" butonunu çıkar
                            $msg.html('<span style="color: #d63638; font-weight: bold; margin-right:10px;">Yeni Sürüm Var (' + response.data.new_version + ')</span>');
                            $btn.replaceWith('<button type="button" class="button button-primary ferdimen-do-update-btn" data-slug="'+$btn.data('slug')+'">Güncelleme Yap</button>');
                        } else {
                            $msg.html('<span style="color: #46b450;">Eklenti Güncel.</span>');
                        }
                    } else {
                        $msg.html('<span style="color: #d63638;">Hata: ' + response.data + '</span>');
                    }
                },
                error: function() {
                    $spinner.removeClass('is-active');
                    $btn.prop('disabled', false);
                    $msg.html('<span style="color: #d63638;">Bağlantı hatası oluştu.</span>');
                }
            });
        });

        // GÜNCELLEME YAP BUTONU (Dinamik oluştuğu için döküman üzerinden yakalıyoruz)
        $(document).on('click', '.ferdimen-do-update-btn', function(e) {
            e.preventDefault();
            var $btn = $(this);
            var $row = $btn.closest('tr');
            var $spinner = $row.find('.spinner');
            var $msg = $row.find('.update-message');
            var pluginFile = $row.data('plugin');

            if(!confirm('Bu eklentiyi güncellemek istediğinize emin misiniz?')) return;

            $btn.prop('disabled', true);
            $spinner.addClass('is-active');
            $msg.html('Güncelleniyor...');

            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'ferdimen_run_plugin_update',
                    plugin_file: pluginFile,
                    nonce: '<?php echo wp_create_nonce("ferdimen_update_nonce"); ?>'
                },
                success: function(response) {
                    $spinner.removeClass('is-active');
                    if (response.success) {
                        $msg.html('<span style="color: #46b450; font-weight: bold;">Başarıyla Güncellendi! Sayfayı yenileyebilirsiniz.</span>');
                        $btn.remove();
                    } else {
                        $btn.prop('disabled', false);
                        $msg.html('<span style="color: #d63638;">Güncelleme başarısız: ' + response.data + '</span>');
                    }
                },
                error: function() {
                    $spinner.removeClass('is-active');
                    $btn.prop('disabled', false);
                    $msg.html('<span style="color: #d63638;">Sistem hatası.</span>');
                }
            });
        });
    });
    </script>
    <?php
}

// 2. AJAX: Güncelleme Denetleme Fonksiyonu
add_action('wp_ajax_ferdimen_check_plugin_update', 'ferdimen_check_plugin_update_callback');
function ferdimen_check_plugin_update_callback() {
    check_ajax_referer('ferdimen_update_nonce', 'nonce');

    if (!current_user_can('update_plugins')) {
        wp_send_json_error('Yetkiniz yetersiz.');
    }

    $plugin_file = sanitize_text_field($_POST['plugin_file']);

    // WordPress'in güncelleme havuzunu zorla yeniliyoruz (API'den güncel listeyi çekmesi için)
    wp_clean_plugins_cache();
    wp_update_plugins();

    // Güncelleme transient verisini kontrol et
    $current = get_site_transient('update_plugins');
    
    if (isset($current->response[$plugin_file])) {
        $update_info = $current->response[$plugin_file];
        wp_send_json_success([
            'update_available' => true,
            'new_version'      => $update_info->new_version
        ]);
    } else {
        wp_send_json_success([
            'update_available' => false
        ]);
    }
}

// 3. AJAX: Güncelleme İşlemini Gerçekleştiren Fonksiyon
add_action('wp_ajax_ferdimen_run_plugin_update', 'ferdimen_run_plugin_update_callback');
function ferdimen_run_plugin_update_callback() {
    check_ajax_referer('ferdimen_update_nonce', 'nonce');

    if (!current_user_can('update_plugins')) {
        wp_send_json_error('Yetkiniz yetersiz.');
    }

    $plugin_file = sanitize_text_field($_POST['plugin_file']);

    // Gerekli WordPress sınıflarını yükle
    include_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
    include_once ABSPATH . 'wp-admin/includes/plugin-installation.php';
    include_once ABSPATH . 'wp-admin/includes/theme.php';
    include_once ABSPATH . 'wp-admin/includes/file.php';

    // Arka planda çıktı basılmasını engellemek için Buffer kullanıyoruz
    ob_start();
    
    // Görünmez bir şekilde güncelleme işlemini yürütecek Skin yapısı
    $skin = new Automatic_Upgrader_Skin();
    $upgrader = new Plugin_Upgrader($skin);
    $result = $upgrader->upgrade($plugin_file);
    
    ob_end_clean();

    if (is_wp_error($result)) {
        wp_send_json_error($result->get_error_message());
    } elseif ($result === true) {
        // Güncelleme sonrası eklentiyi isterseniz otomatik yeniden aktif edebilirsiniz
        // (Upgrade işlemi bazen eklentiyi deaktive edebilir)
        ensure_active_login_plugins_if_needed($plugin_file);
        
        wp_send_json_success('Güncelleme tamamlandı.');
    } else {
        wp_send_json_error('Bilinmeyen bir hata oluştu veya eklenti zaten güncel.');
    }
}

// Eklenti güncellendikten sonra aktiflik kontrolü (opsiyonel yardımcı fonksiyon)
function ensure_active_login_plugins_if_needed($plugin_file) {
    $active_plugins = get_option('active_plugins');
    if (!in_array($plugin_file, $active_plugins)) {
        activate_plugin($plugin_file);
    }
}