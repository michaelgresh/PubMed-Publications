<?php
/**
 * Plugin Name: PubMed Publications
 * Description: Pulls PubMed/NCBI publications per doctor and renders Divi-friendly lists with optional bibliography links.
 * Version: 1.2.0
 * Author: Michael Gresh - Alt Dev Studios
 */

if (!defined('ABSPATH')) exit;

class PubMed_Publications {
  const TOOL = 'pubmed_publications';
  const EMAIL = 'youremailhere@notreal.com'; // TODO: set to a monitored email (NCBI guideline)
  const CPT = 'pubmed_publication';
  const TAX = 'pubmed_doctor';
  const CACHE_HOURS = 6;

  private static $modal_printed = false;

  public function __construct() {
    add_action('init', [$this, 'register_types']);
    add_action('admin_menu', [$this, 'admin_menu']);
    add_action('admin_post_pubmed_save_doctors', [$this, 'handle_save_doctors']);
    add_action('admin_post_pubmed_fetch', [$this, 'handle_fetch']);
    add_action('wp_enqueue_scripts', [$this, 'enqueue_front']);
    add_shortcode('pubmed_publications', [$this, 'sc_publications']);
    add_shortcode('pubmed_latest_publications', [$this, 'sc_latest']);
    add_action('admin_post_pubmed_rebuild_dates', [$this,'handle_rebuild_dates']);
    add_action('admin_post_pubmed_delete_doctor', [$this, 'handle_delete_doctor']);
    add_action('admin_notices', [$this, 'admin_notices']);
  }

  /* ---------------- Types ---------------- */
  public function register_types() {
    register_post_type(self::CPT, [
      'labels' => [
        'name'               => 'Publications',
        'singular_name'      => 'Publication',
        'menu_name'          => 'Publications',
        'all_items'          => 'All Publications',
        'add_new'            => 'Add New',
        'add_new_item'       => 'Add New Publication',
        'edit_item'          => 'Edit Publication',
        'new_item'           => 'New Publication',
        'view_item'          => 'View Publication',
        'search_items'       => 'Search Publications',
      ],
      'public'        => true,
      'show_ui'       => true,
      'show_in_menu'  => 'pubmed-pubs',
      'supports'      => ['title','editor','custom-fields'],
      'has_archive'   => false,
      'rewrite'       => ['slug' => 'publication'],
    ]);

    register_taxonomy(self::TAX, self::CPT, [
      'label'             => 'Doctors',
      'public'            => true,
      'hierarchical'      => false,
      'show_admin_column' => true,
    ]);
  }

  /* ---------------- Admin UI ---------------- */
  public function admin_menu() {
    // Top-level
    add_menu_page(
      'PubMed Publications',
      'PubMed Publications',
      'manage_options',
      'pubmed-pubs',
      [$this,'admin_page'],
      'dashicons-media-document',
      26
    );

    // Submenu: Doctors & Import (our screen)
    add_submenu_page(
      'pubmed-pubs',
      'Doctors & Import',
      'Doctors & Import',
      'manage_options',
      'pubmed-doctors',
      [$this,'admin_page']
    );

    // Optional: hide the auto “PubMed Publications” submenu WordPress adds
    add_action('admin_menu', function(){
      remove_submenu_page('pubmed-pubs','pubmed-pubs');
    }, 100);
  }

  private function get_doctors() {
    return get_terms(['taxonomy'=>self::TAX,'hide_empty'=>false]);
  }

  public function admin_page() {
    if (!current_user_can('manage_options')) return;
    $doctors = $this->get_doctors();
    ?>
    <div class="wrap">
      <h1>PubMed Publications — Doctors &amp; Import</h1>

      <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin:1rem 0 2rem;">
        <?php wp_nonce_field('pubmed_save_doctors'); ?>
        <input type="hidden" name="action" value="pubmed_save_doctors"/>
        <h2>Add / Edit Doctor</h2>
        <p>Add a doctor (creates/updates a taxonomy term), store a PubMed query and an optional bibliography URL.</p>
        <table class="form-table">
          <tr>
            <th><label for="pubmed_doctor_name">Doctor name (term)</label></th>
            <td><input type="text" id="pubmed_doctor_name" name="doctor_name" class="regular-text" placeholder="e.g., Henderson" required></td>
          </tr>
          <tr>
            <th><label for="pubmed_query">PubMed query</label></th>
            <td>
              <input type="text" id="pubmed_query" name="query" class="large-text" placeholder="e.g., (Henderson A[au] OR Henderson AM[au]) AND (YourHospital[ad] OR YourCity[ad])">
              <p class="description">Supports boolean OR and parentheses. Avoid wrapping the entire expression in quotes.</p>
            </td>
          </tr>
          <tr>
            <th><label for="pubmed_bib_url">Bibliography URL</label></th>
            <td>
              <input type="url" id="pubmed_bib_url" name="bib_url" class="large-text"
                    placeholder="https://pubmed.ncbi.nlm.nih.gov/?term=Henderson+AM%5Bau%5D&amp;sort=date">
              <p class="description">If this is a PubMed results URL (has <code>?term=...</code>), the term is used for imports. MyNCBI bibliography pages will be shown to users as a link, but imports will use the query above.</p>
            </td>
          </tr>
        </table>
        <p><button class="button button-primary">Save Doctor</button></p>
      </form>

      <h2>Doctors</h2>
      <table class="widefat striped">
        <thead><tr><th>Name</th><th>Query / Bibliography</th><th>Actions</th></tr></thead>
        <tbody>
        <?php if ($doctors): foreach ($doctors as $d):
          $q   = get_term_meta($d->term_id, 'pubmed_query', true);
          $bib = get_term_meta($d->term_id, 'pubmed_bib_url', true); ?>
          <tr>
            <td><strong><?php echo esc_html($d->name); ?></strong></td>
            <td>
              <div><strong>Query:</strong> <code><?php echo esc_html($q ?: '—'); ?></code></div>
              <?php if ($bib): ?>
                <div>📚 <a href="<?php echo esc_url($bib); ?>" target="_blank" rel="noopener">Bibliography</a></div>
              <?php endif; ?>
            </td>
            <td>
              <!-- Fetch/Refresh -->
              <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline;margin-right:.5rem;">
                <?php wp_nonce_field('pubmed_fetch'); ?>
                <input type="hidden" name="action" value="pubmed_fetch"/>
                <input type="hidden" name="doctor_term_id" value="<?php echo esc_attr($d->term_id); ?>"/>
                <input type="hidden" name="force" value="1"/>
                <button class="button">Fetch/Refresh</button>
              </form>

              <!-- Delete doctor -->
              <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline;">
                <?php wp_nonce_field('pubmed_delete_doctor_'.$d->term_id); ?>
                <input type="hidden" name="action" value="pubmed_delete_doctor"/>
                <input type="hidden" name="doctor_term_id" value="<?php echo esc_attr($d->term_id); ?>"/>
                <select name="delete_mode">
                  <option value="keep">Delete doctor (keep publications)</option>
                  <option value="delete_posts">Delete doctor + publications (exclusive only)</option>
                </select>
                <button class="button button-link-delete" onclick="return confirm('Are you sure you want to delete this doctor? This cannot be undone.');">
                  Delete
                </button>
              </form>
            </td>
          </tr>
        <?php endforeach; else: ?>
          <tr><td colspan="3">No doctors created yet.</td></tr>
        <?php endif; ?>
        </tbody>
      </table>

      <?php if ($doctors): ?>
      <!-- Bulk Fetch All -->
      <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-top:1rem;">
        <?php wp_nonce_field('pubmed_fetch'); ?>
        <input type="hidden" name="action" value="pubmed_fetch"/>
        <input type="hidden" name="doctor_term_id" value="all"/>
        <input type="hidden" name="force" value="1"/>
        <button class="button button-secondary">Fetch/Refresh ALL</button>
      </form>

      <!-- Rebuild Dates -->
      <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-top:1rem;">
        <?php wp_nonce_field('pubmed_rebuild_dates'); ?>
        <input type="hidden" name="action" value="pubmed_rebuild_dates"/>
        <button class="button">Rebuild Dates (fix sorting)</button>
      </form>
      <?php endif; ?>
    </div>
    <?php
  }

  public function handle_rebuild_dates() {
    if (!current_user_can('manage_options') || !check_admin_referer('pubmed_rebuild_dates')) wp_die('Not allowed');

    $q = new WP_Query([
      'post_type'      => self::CPT,
      'posts_per_page' => -1,
      'fields'         => 'ids',
      'post_status'    => 'publish',
    ]);

    foreach ($q->posts as $post_id) {
      $iso = get_post_meta($post_id, 'pubmed_pubdate_iso', true);
      $raw = get_post_meta($post_id, 'pubmed_pubdate', true);
      if (!$iso && $raw) {
        $iso = $this->to_iso_date(trim($raw));
        if ($iso) update_post_meta($post_id, 'pubmed_pubdate_iso', $iso);
      }
      if ($iso) $this->set_wp_dates_from_iso($post_id, $iso);
    }

    wp_redirect(admin_url('admin.php?page=pubmed-pubs'));
    exit;
  }

  public function handle_save_doctors() {
    if (!current_user_can('manage_options') || !check_admin_referer('pubmed_save_doctors')) wp_die('Not allowed');
    $name = sanitize_text_field($_POST['doctor_name'] ?? '');
    $query = sanitize_text_field($_POST['query'] ?? '');
    $bib_url = esc_url_raw($_POST['bib_url'] ?? '');

    $query = preg_replace('/[“”]/u', '"', $query);
    $query = preg_replace("/[‘’]/u", "'", $query);
    $query = preg_replace('/^(["\'])(.*)\1$/', '$2', trim($query));

    if (!$name) wp_redirect(admin_url('admin.php?page=pubmed-pubs'));

    $term = term_exists($name, self::TAX);
    if (!$term) $term = wp_insert_term($name, self::TAX);

    if (!is_wp_error($term)) {
      $tid = is_array($term) ? ($term['term_id'] ?? 0) : $term->term_id;
      if ($tid) {
        update_term_meta($tid, 'pubmed_query', $query);
        update_term_meta($tid, 'pubmed_bib_url', $bib_url);
      }
    }
    wp_redirect(admin_url('admin.php?page=pubmed-pubs'));
    exit;
  }

  public function handle_fetch() {
    if (!current_user_can('manage_options') || !check_admin_referer('pubmed_fetch')) wp_die('Not allowed');
    $term_id = $_POST['doctor_term_id'] ?? '';
    $force = !empty($_POST['force']);
    if ($term_id === 'all') {
      foreach ($this->get_doctors() as $d) $this->fetch_and_store_for_doctor($d, $force);
    } else {
      $term = get_term((int)$term_id, self::TAX);
      if ($term && !is_wp_error($term)) $this->fetch_and_store_for_doctor($term, $force);
    }
    wp_redirect(admin_url('admin.php?page=pubmed-pubs'));
    exit;
  }

  public function handle_delete_doctor() {
    if (!current_user_can('manage_options')) wp_die('Not allowed');

    $term_id = isset($_POST['doctor_term_id']) ? (int) $_POST['doctor_term_id'] : 0;
    if (!$term_id) wp_die('Missing doctor term id');

    // Nonce tied to specific term id
    if (!isset($_POST['_wpnonce']) || !wp_verify_nonce($_POST['_wpnonce'], 'pubmed_delete_doctor_'.$term_id)) {
      wp_die('Invalid request');
    }

    $mode = isset($_POST['delete_mode']) ? sanitize_text_field($_POST['delete_mode']) : 'keep';

    $term = get_term($term_id, self::TAX);
    if (!$term || is_wp_error($term)) {
      wp_safe_redirect(admin_url('admin.php?page=pubmed-pubs'));
      exit;
    }

    // Find publications currently tagged with this doctor
    $q = new WP_Query([
      'post_type'      => self::CPT,
      'posts_per_page' => -1,
      'fields'         => 'ids',
      'tax_query'      => [[
        'taxonomy'         => self::TAX,
        'field'            => 'term_id',
        'terms'            => [$term_id],
        'include_children' => false,
      ]],
    ]);

    if ($mode === 'delete_posts') {
      foreach ($q->posts as $post_id) {
        $attached = wp_get_object_terms($post_id, self::TAX, ['fields' => 'ids']);
        if (is_wp_error($attached)) continue;

        if (count($attached) <= 1) {
          // Publication belongs only to this doctor → move to Trash (safer than force delete)
          wp_delete_post($post_id, false);
        } else {
          // Co-authored: Just detach this doctor
          wp_remove_object_terms($post_id, [$term_id], self::TAX);
        }
      }
    } else {
      // Keep publications: Just detach this doctor from any posts
      foreach ($q->posts as $post_id) {
        wp_remove_object_terms($post_id, [$term_id], self::TAX);
      }
    }

    // Finally delete the doctor term
    wp_delete_term($term_id, self::TAX);

    wp_safe_redirect(add_query_arg([
      'page'         => 'pubmed-pubs',
      'pubmed_notice'=> 'doctor_deleted',
      'mode'         => $mode,
    ], admin_url('admin.php')));
    exit;
  }

  public function admin_notices() {
    if (!current_user_can('manage_options')) return;
    if (empty($_GET['pubmed_notice'])) return;

    // Only show on our plugin screens (prevents notices leaking elsewhere)
    if (function_exists('get_current_screen')) {
      $screen = get_current_screen();
      if ($screen && strpos($screen->id, 'pubmed-pubs') === false) return;
    }

    $notice = sanitize_text_field($_GET['pubmed_notice']);
    $mode   = isset($_GET['mode']) ? sanitize_text_field($_GET['mode']) : '';

    $class = 'success';
    $msg   = '';

    switch ($notice) {
      case 'doctor_deleted':
        if ($mode === 'delete_posts') {
          $msg = 'Doctor removed. Any publications that were exclusive to this doctor were moved to the Trash. Co-authored publications were kept (with this doctor unassigned).';
        } else {
          $msg = 'Doctor removed. All publications were kept (with this doctor unassigned).';
        }
        break;

      default:
        return;
    }

    printf(
      '<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>',
      esc_attr($class),
      esc_html($msg)
    );
  }

  /* ---------------- Fetch & Store ---------------- */

  private function fetch_and_store_for_doctor($term, $force=false) {
    $query = $this->derive_query_from_term($term);
    if (!$query) return;

    $results = $this->ncbi_search_and_summary($query, 100, $force);
    if (is_wp_error($results)) return;

    foreach ($results as $r) {
      $pmid  = $r['pmid'] ?? '';
      $title = wp_strip_all_tags($r['title'] ?? '');
      if (!$pmid && !$title) continue;

      // Prefer dedup by PMID
      $existing = [];
      if ($pmid) {
        $existing = get_posts([
          'post_type'      => self::CPT,
          'posts_per_page' => 1,
          'fields'         => 'ids',
          'meta_query'     => [[ 'key' => 'pubmed_pmid', 'value' => $pmid ]]
        ]);
      }
      // Fallback: title
      if (!$existing && $title) {
        $existing = get_posts([
          'post_type'      => self::CPT,
          'title'          => $title,
          'posts_per_page' => 1,
          'fields'         => 'ids',
        ]);
      }

      $postarr = [
        'post_type'   => self::CPT,
        'post_status' => 'publish',
        'post_title'  => $title ?: ('PMID '.$pmid),
        'post_content'=> '',
      ];

      $post_id = $existing ? $existing[0] : wp_insert_post($postarr);
      if ($existing) { $postarr['ID'] = $post_id; wp_update_post($postarr); }

      // Dates (store display + ISO; sync WP post_date)
      $display = $r['display_date'] ?? ($r['epubdate'] ?? $r['pubdate'] ?? '');
      $iso     = $r['iso_date']     ?? $this->iso_from_dates($r['sortpubdate'] ?? '', $r['epubdate'] ?? '', $r['pubdate'] ?? '');

      update_post_meta($post_id, 'pubmed_pubdate',          $display); // legacy
      update_post_meta($post_id, 'pubmed_pubdate_display',  $display);
      update_post_meta($post_id, 'pubmed_pubdate_iso',      $iso);
      $this->set_wp_dates_from_iso($post_id, $iso);

      // Other meta
      update_post_meta($post_id, 'pubmed_pmid',    $pmid);
      update_post_meta($post_id, 'pubmed_pmcid',   $r['pmcid'] ?? '');
      update_post_meta($post_id, 'pubmed_doi',     $r['doi'] ?? '');
      update_post_meta($post_id, 'pubmed_journal', $r['journal'] ?? '');
      update_post_meta($post_id, 'pubmed_authors', implode(', ', $r['authors'] ?? []));
      $pm_url = $r['pubmed_url'] ?? ($pmid ? 'https://pubmed.ncbi.nlm.nih.gov/'.$pmid.'/' : '');
      update_post_meta($post_id, 'pubmed_url', $pm_url);

      // Attach taxonomy (doctor)
      wp_set_object_terms($post_id, [$term->term_id], self::TAX, true);
    }
  }

  private function derive_query_from_term($term) {
    // Prefer PubMed 'term' from bibliography URL if present
    $bib_url = get_term_meta($term->term_id, 'pubmed_bib_url', true);
    if ($bib_url) {
      $host = parse_url($bib_url, PHP_URL_HOST) ?: '';
      if (preg_match('/(pubmed\.ncbi\.nlm\.nih\.gov|ncbi\.nlm\.nih\.gov)$/i', $host)) {
        $qs = [];
        parse_str(parse_url($bib_url, PHP_URL_QUERY) ?? '', $qs);
        if (!empty($qs['term'])) {
          $query = $qs['term'];
          $query = preg_replace('/[“”]/u', '"', $query);
          $query = preg_replace("/[‘’]/u", "'", $query);
          $query = preg_replace('/^(["\'])(.*)\1$/', '$2', trim($query));
          return $query;
        }
      }
    }
    // Fallback to stored manual query
    $query = get_term_meta($term->term_id, 'pubmed_query', true);
    return $query ?: '';
  }

  private function to_iso_date($pubdate) {
    // Try to coerce PubMed "YYYY Mon DD" / "YYYY Mon" / "YYYY" into YYYY-MM-DD
    if (!$pubdate) return '';
    $t = strtotime($pubdate);
    if ($t) return date('Y-m-d', $t);
    if (preg_match('/^\d{4}$/', $pubdate)) return $pubdate.'-12-31';
    if (preg_match('/^(\d{4})\s+([A-Za-z]{3,})$/', $pubdate, $m)) {
      $month = date('m', strtotime('01 '.$m[2].' '.$m[1]));
      return $m[1].'-'.$month.'-28';
    }
    return '';
  }

  private function iso_from_dates($sortpub, $epub, $pub) {
    if ($sortpub && preg_match('/^(\d{4})(?:\/(\d{2}))?(?:\/(\d{2}))?/', $sortpub, $m)) {
      $y = (int)$m[1]; $mo = isset($m[2]) ? (int)$m[2] : 12; $da = isset($m[3]) ? (int)$m[3] : 31;
      return sprintf('%04d-%02d-%02d', $y, $mo, $da);
    }
    $iso = $this->to_iso_date($epub ?: '');
    if ($iso) return $iso;
    return $this->to_iso_date($pub ?: '');
  }

  private function nice_display_date($epub, $pub) {
    return $epub ?: ($pub ?: '');
  }

  private function set_wp_dates_from_iso($post_id, $iso) {
    if (!$iso) return;
    $post_date     = $iso . ' 00:00:00';
    $post_date_gmt = get_gmt_from_date($post_date, 'Y-m-d H:i:s');
    wp_update_post([
      'ID'            => $post_id,
      'post_date'     => $post_date,
      'post_date_gmt' => $post_date_gmt,
      'edit_date'     => true,
    ]);
  }

  private function ncbi_search_and_summary($term, $retmax=30, $force=false) {
    $cache_key = 'pubmed_ncbi_' . md5($term.'|'.$retmax);
    if (!$force) {
      $cached = get_transient($cache_key);
      if ($cached !== false) return $cached;
    }

    $base = [
      'db'      => 'pubmed',
      'retmode' => 'json',
      'tool'    => self::TOOL,
      'email'   => self::EMAIL,
    ];

    $esearch_url = add_query_arg(array_merge($base,[
      'term'   => $term,
      'sort'   => 'pub+date',
      'retmax' => $retmax,
    ]), 'https://eutils.ncbi.nlm.nih.gov/entrez/eutils/esearch.fcgi');

    $r = wp_remote_get($esearch_url, ['timeout'=>20]);
    if (is_wp_error($r)) return $r;
    if (wp_remote_retrieve_response_code($r)!==200) return new WP_Error('http','ESearch failed');

    $ids = json_decode(wp_remote_retrieve_body($r), true)['esearchresult']['idlist'] ?? [];
    if (!$ids) { set_transient($cache_key, [], HOUR_IN_SECONDS*self::CACHE_HOURS); return []; }

    $esummary_url = add_query_arg(array_merge($base,['id'=>implode(',',$ids)]),
      'https://eutils.ncbi.nlm.nih.gov/entrez/eutils/esummary.fcgi');

    $r2 = wp_remote_get($esummary_url, ['timeout'=>20]);
    if (is_wp_error($r2)) return $r2;
    if (wp_remote_retrieve_response_code($r2)!==200) return new WP_Error('http','ESummary failed');

    $sum = json_decode(wp_remote_retrieve_body($r2), true);
    $out = [];

    foreach ($sum['result']['uids'] ?? [] as $pmid) {
      $item = $sum['result'][$pmid] ?? null; if (!$item) continue;

      $pubdate  = $item['pubdate'] ?? '';
      $epubdate = $item['epubdate'] ?? '';
      $sortdate = $item['sortpubdate'] ?? '';

      $authors = array_map(function($a){ return $a['name'] ?? ''; }, $item['authors'] ?? []);
      $doi=''; $pmcid='';
      foreach (($item['articleids'] ?? []) as $ai) {
        if (($ai['idtype']??'')==='doi')   $doi   = $ai['value'];
        if (($ai['idtype']??'')==='pmcid') $pmcid = $ai['value'];
      }

      $out[] = [
        'pmid'         => $pmid,
        'title'        => $item['title'] ?? '',
        'journal'      => $item['fulljournalname'] ?? ($item['source'] ?? ''),
        'pubdate'      => $pubdate,
        'epubdate'     => $epubdate,
        'sortpubdate'  => $sortdate,
        'iso_date'     => $this->iso_from_dates($sortdate, $epubdate, $pubdate),
        'display_date' => $this->nice_display_date($epubdate, $pubdate),
        'authors'      => array_filter($authors),
        'doi'          => $doi,
        'pmcid'        => $pmcid,
        'pubmed_url'   => 'https://pubmed.ncbi.nlm.nih.gov/'.$pmid.'/',
      ];
    }

    set_transient($cache_key, $out, HOUR_IN_SECONDS*self::CACHE_HOURS);
    return $out;
  }

  /* ---------------- Frontend ---------------- */

  public function enqueue_front() {
    // Minimal CSS (inherits site fonts/colors)
    $css = "
    .pubmed-section-header{margin:1rem 0 .75rem}
    .pubmed-section-title{margin:0 0 .25rem}
    .pubmed-section-subtitle{opacity:.85;font-size:.95rem}
    .pubmed-bib-link{margin:.25rem 0 1rem}
    .pubmed-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(300px,1fr));gap:1rem}
    .pubmed-card{background:#fff;border:1px solid rgba(0,0,0,.08);border-radius:12px;padding:1rem 1.1rem;box-shadow:0 1px 3px rgba(0,0,0,.05)}
    .pubmed-title{font-weight:600;margin:0 0 .35rem;line-height:1.35}
    .pubmed-meta{font-size:.9rem;opacity:.9;margin-bottom:.35rem}
    .pubmed-authors{font-size:.9rem;margin-bottom:.5rem}
    .pubmed-links a{margin-right:.6rem;text-decoration:underline}
    ";
    wp_register_style('pubmed-pubs', false);
    wp_add_inline_style('pubmed-pubs', $css);
    wp_enqueue_style('pubmed-pubs');
  }

  private function section_header($title = '', $subtitle = '', $anchor = '', $heading = 'h2') {
    if (!$title) return '';
    $heading = in_array(strtolower($heading), ['h2','h3','h4','h5']) ? strtolower($heading) : 'h2';
    $id = $anchor ? ' id="'.esc_attr($anchor).'"' : '';
    ob_start(); ?>
    <div class="pubmed-section-header"<?php echo $id; ?>>
      <<?php echo $heading; ?> class="pubmed-section-title"><?php echo esc_html($title); ?></<?php echo $heading; ?>>
      <?php if ($subtitle): ?>
        <div class="pubmed-section-subtitle"><?php echo esc_html($subtitle); ?></div>
      <?php endif; ?>
    </div>
    <?php
    return ob_get_clean();
  }

  private function render_cards($posts, $open='newtab') {
    ob_start();
    echo '<div class="pubmed-grid">';
    foreach ($posts as $p) $this->card($p, $open);
    echo '</div>';
    if ($open==='modal') $this->modal_script_once();
    return ob_get_clean();
  }

  private function card($p, $open) {
    $title   = esc_html(get_the_title($p));
    $journal = esc_html(get_post_meta($p->ID,'pubmed_journal',true));
    $display = get_post_meta($p->ID,'pubmed_pubdate_display',true);
    $pubdate = esc_html($display ?: get_post_meta($p->ID,'pubmed_pubdate',true));
    $authors = esc_html(get_post_meta($p->ID,'pubmed_authors',true));
    $pmid    = get_post_meta($p->ID,'pubmed_pmid',true);
    $doi     = get_post_meta($p->ID,'pubmed_doi',true);
    $pmcid   = get_post_meta($p->ID,'pubmed_pmcid',true);
    $url     = get_post_meta($p->ID,'pubmed_url',true) ?: ($pmid ? 'https://pubmed.ncbi.nlm.nih.gov/'.$pmid.'/' : '');

    $target = $open==='modal' ? '' : ' target="_blank" rel="noopener"';
    $a_open = $open==='modal' ? ' data-pubmed-modal' : $target;

    echo '<article class="pubmed-card">';
      echo '<h4 class="pubmed-title">'.$title.'</h4>';
      echo '<div class="pubmed-meta">'.($journal?'<em>'.$journal.'</em>':'').($pubdate?' • '.$pubdate:'').'</div>';
      if ($authors) echo '<div class="pubmed-authors"><strong>Authors:</strong> '.$authors.'</div>';
      echo '<div class="pubmed-links">';
        if ($url)   echo '<a href="'.esc_url($url).'"'.$a_open.'>PubMed</a>';
        if ($doi)   echo ' <a href="'.esc_url('https://doi.org/'.rawurlencode($doi)).'"'.$target.'>DOI</a>';
        if ($pmcid) echo ' <a href="'.esc_url('https://www.ncbi.nlm.nih.gov/pmc/articles/'.rawurlencode($pmcid).'/').'"'.$target.'>PMC</a>';
      echo '</div>';
    echo '</article>';
  }

  private function modal_script_once() {
    if (self::$modal_printed) return;
    self::$modal_printed = true; ?>
    <div id="pubmed-modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.65);z-index:99999">
      <div style="position:absolute;top:5%;left:50%;transform:translateX(-50%);width:min(1200px,94vw);height:90vh;background:#fff;border-radius:12px;overflow:hidden">
        <button id="pubmed-modal-close" style="position:absolute;right:10px;top:10px;padding:.4rem .6rem">Close</button>
        <iframe id="pubmed-modal-frame" src="" style="width:100%;height:100%;border:0"></iframe>
      </div>
    </div>
    <script>
    (function(){
      const m=document.getElementById('pubmed-modal');
      const f=document.getElementById('pubmed-modal-frame');
      const c=document.getElementById('pubmed-modal-close');
      const allow=/(\.|^)ncbi\.nlm\.nih\.gov$|(\.|^)pubmed\.ncbi\.nlm\.nih\.gov$/i;
      document.addEventListener('click',function(e){
        const a=e.target.closest('a[data-pubmed-modal]');
        if(!a) return;
        try{
          const u=new URL(a.href);
          if(allow.test(u.hostname)){
            e.preventDefault(); f.src=a.href; m.style.display='block';
          }
        }catch(_){}
      });
      c.addEventListener('click',()=>{ m.style.display='none'; f.src=''; });
    })();
    </script>
    <?php
  }

  /* ---------------- Shortcodes ---------------- */

  public function sc_publications($atts) {
    $a = shortcode_atts([
      'doctor'      => '',
      'limit'       => 10,
      'open'        => 'newtab',
      'year_groups' => 'false',
      'title'       => '',
      'subtitle'    => '',
      'anchor'      => '',
      'heading'     => 'h2',
      'show_bibliography' => 'true',
      'bib_label'   => 'View full bibliography',
    ], $atts);

    $args = [
      'post_type'      => self::CPT,
      'posts_per_page' => intval($a['limit']),
      'orderby'        => 'date',
      'order'          => 'DESC',
      'post_status'    => 'publish',
    ];
    if ($a['doctor']) {
      $args['tax_query'] = [[
        'taxonomy' => self::TAX,
        'field'    => 'name',
        'terms'    => $a['doctor'],
      ]];
    }

    $q = new WP_Query($args);

    $header = $this->section_header($a['title'], $a['subtitle'], $a['anchor'], $a['heading']);

    // Optional bibliography link (shown under the title)
    $bib_html = '';
    if ($a['show_bibliography']==='true' && $a['doctor']) {
      $term = get_term_by('name', $a['doctor'], self::TAX);
      if ($term && !is_wp_error($term)) {
        $bib = get_term_meta($term->term_id, 'pubmed_bib_url', true);
        if ($bib) $bib_html = '<div class="pubmed-bib-link"><a href="'.esc_url($bib).'" target="_blank" rel="noopener">'.esc_html($a['bib_label']).'</a></div>';
      }
    }

    $html = $header . $bib_html . $this->render_cards($q->posts, ($a['open']==='modal'?'modal':'newtab'));
    wp_reset_postdata();
    return $html;
  }

  public function sc_latest($atts) {
    $a = shortcode_atts([
      'limit'    => 10,
      'open'     => 'newtab',
      'title'    => '',
      'subtitle' => '',
      'anchor'   => '',
      'heading'  => 'h2',
    ], $atts);

    $q = new WP_Query([
      'post_type'      => self::CPT,
      'posts_per_page' => intval($a['limit']),
      'orderby'        => 'date',
      'order'          => 'DESC',
      'post_status'    => 'publish',
    ]);

    $header = $this->section_header($a['title'], $a['subtitle'], $a['anchor'], $a['heading']);
    $html = $header . $this->render_cards($q->posts, ($a['open']==='modal'?'modal':'newtab'));
    wp_reset_postdata();
    return $html;
  }

}

new PubMed_Publications();
