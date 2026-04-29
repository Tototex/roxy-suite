<?php
namespace RoxyGrosses;
if (!defined('ABSPATH')) exit;

class Settings {
  public const OPTION_KEY='roxy_grosses_settings';
  public const STATUS_KEY='roxy_grosses_last_report';
  private const WORKBOOK_TEMPLATE_UPLOAD_KEY='workbook_template_upload';
  private const ENCRYPTION_PREFIX='gcm:';

  public static function init(): void {
    add_action('admin_init',[__CLASS__,'register_settings']);
  }
  public static function current_tab(): string {
    $tab=isset($_GET['tab'])?sanitize_key((string) wp_unslash($_GET['tab'])):'database';
    if ($tab === 'imports') {
      $tab = 'workbook';
    }
    return in_array($tab,['database','live-shows','rentals','legacy-weekly','workbook','daily','settings','logs'],true)?$tab:'database';
  }
  public static function defaults(): array {
    return [
      'square_environment'=>'production','square_access_token'=>'','square_location_ids'=>'',
      'report_timezone'=>wp_timezone_string()?:'America/Los_Angeles',
      'ticket_keywords'=>"ticket\nadmission",'exclude_keywords'=>"popcorn\nsoda\ndrink\ncandy\nmembership",
      'film_mappings'=>'','studio_mappings'=>'',
      'recipient_emails'=>'comscore@example.com,lori@example.com','advertiser_emails'=>'','live_email_recipients'=>'','admin_email'=>get_option('admin_email'),
      'email_subject'=>'Roxy grosses for {report_date}',
      'email_body'=>"Attached is the grosses report for {report_date}.\n\nGenerated automatically by the Roxy Grosses plugin.",
      'advertiser_email_subject'=>'Roxy advertiser summary for {month_name} {year}',
      'advertiser_email_body'=>"Attached is the advertiser summary workbook for {month_name} {year}.",
      'theater_name'=>'Newport Roxy Theater','general_price'=>'12','discount_price'=>'8','group_price'=>'5','lookback_days'=>'0',
      'workbook_template_path'=>'I:\\My Drive\\Grosses\\Roxy_Box_Office_{year}.xlsx',
      'schedule_enabled'=>'1','schedule_time'=>'20:00',
      'advertiser_schedule_enabled'=>'1','advertiser_schedule_day'=>'1','advertiser_schedule_time'=>'09:00',
    ];
  }
  public static function ensure_defaults(): void {
    if (get_option(self::OPTION_KEY,null)===null) add_option(self::OPTION_KEY,self::defaults());
  }
  public static function get_all(): array {
    $saved=get_option(self::OPTION_KEY,[]); if(!is_array($saved)) $saved=[];
    $all = wp_parse_args($saved,self::defaults());
    $all['square_access_token'] = self::decrypt_secret((string) ($saved['square_access_token'] ?? $all['square_access_token']));
    return $all;
  }
  public static function get(string $key,$default=''){ $all=self::get_all(); return array_key_exists($key,$all)?$all[$key]:$default; }
  public static function get_report_timezone(): string {
    $timezone=sanitize_text_field((string) self::get('report_timezone',wp_timezone_string()?:'America/Los_Angeles'));
    try { new \DateTimeZone($timezone); return $timezone; } catch (\Exception $e) { return wp_timezone_string()?:'America/Los_Angeles'; }
  }
  public static function get_status(): array { $status=get_option(self::STATUS_KEY,[]); return is_array($status)?$status:[]; }
  public static function set_status(array $status): void {
    update_option(self::STATUS_KEY,[
      'sent_at'=>sanitize_text_field((string) ($status['sent_at']??'')),
      'report_date'=>sanitize_text_field((string) ($status['report_date']??'')),
      'mode'=>sanitize_text_field((string) ($status['mode']??'')),
      'message'=>sanitize_text_field((string) ($status['message']??'')),
      'row_count'=>max(0,(int) ($status['row_count']??0)),
      'gross_total'=>round((float) ($status['gross_total']??0),2),
    ]);
  }

  public static function register_settings(): void {
    register_setting(self::OPTION_KEY,self::OPTION_KEY,['type'=>'array','sanitize_callback'=>[__CLASS__,'sanitize'],'default'=>self::defaults()]);
    add_settings_section('roxy_grosses_square','Square',fn()=>print('<p>Connect to Square and define how ticket line items should be recognized.</p>'),'roxy-grosses');
    add_settings_section('roxy_grosses_email','Email',fn()=>print('<p>Choose recipients and the contents of the generated daily and monthly email messages.</p>'),'roxy-grosses');
    add_settings_section('roxy_grosses_schedule','Schedule',fn()=>print('<p>Grosses automation runs every day at the selected time. Use the manual forms only for one-off testing or backfills.</p>'),'roxy-grosses');
    $fields=[
      'square_environment'=>['Square environment','roxy_grosses_square'],'square_access_token'=>['Square access token','roxy_grosses_square'],
      'square_location_ids'=>['Square location IDs','roxy_grosses_square'],'report_timezone'=>['Report timezone','roxy_grosses_square'],
      'ticket_keywords'=>['Ticket keywords','roxy_grosses_square'],'exclude_keywords'=>['Exclude keywords','roxy_grosses_square'],
      'film_mappings'=>['Film mappings','roxy_grosses_square'],'studio_mappings'=>['Studio overrides','roxy_grosses_square'],'recipient_emails'=>['Daily grosses recipient emails','roxy_grosses_email'],
      'live_email_recipients'=>['Live grosses recipient emails','roxy_grosses_email'],
      'admin_email'=>['Admin alert email','roxy_grosses_email'],'email_subject'=>['Email subject','roxy_grosses_email'],
      'email_body'=>['Email body','roxy_grosses_email'],'theater_name'=>['Theater name','roxy_grosses_email'],
      'general_price'=>['General ticket price','roxy_grosses_email'],'discount_price'=>['Discount ticket price','roxy_grosses_email'],
      'group_price'=>['Group ticket price','roxy_grosses_email'],'lookback_days'=>['Previous days to include','roxy_grosses_email'],
      'advertiser_emails'=>['Advertiser emails','roxy_grosses_email'],'advertiser_email_subject'=>['Advertiser email subject','roxy_grosses_email'],
      'advertiser_email_body'=>['Advertiser email body','roxy_grosses_email'],'schedule_enabled'=>['Enable automatic grosses sync and sends','roxy_grosses_schedule'],
      'schedule_time'=>['Automatic grosses run time','roxy_grosses_schedule'],
      'advertiser_schedule_enabled'=>['Enable monthly advertiser email','roxy_grosses_schedule'],'advertiser_schedule_day'=>['Monthly advertiser send day','roxy_grosses_schedule'],'advertiser_schedule_time'=>['Monthly advertiser send time','roxy_grosses_schedule'],
    ];
    foreach($fields as $key=>$field){ add_settings_field($key,$field[0],[__CLASS__,'render_field'],'roxy-grosses',$field[1],['key'=>$key]); }
  }

  public static function sanitize($input): array {
    $d=self::defaults(); $input=is_array($input)?$input:[];
    $existing = get_option(self::OPTION_KEY, []);
    if (!is_array($existing)) {
      $existing = [];
    }
    $incoming_square_token = sanitize_text_field((string) ($input['square_access_token'] ?? ''));
    $square_token = $incoming_square_token !== ''
      ? self::encrypt_secret($incoming_square_token)
      : sanitize_text_field((string) ($existing['square_access_token'] ?? $d['square_access_token']));
    $sanitized=[
      'square_environment'=>in_array(($input['square_environment']??''),['production','sandbox'],true)?$input['square_environment']:$d['square_environment'],
      'square_access_token'=>$square_token,
      'square_location_ids'=>self::sanitize_line_list((string) ($input['square_location_ids']??$d['square_location_ids'])),
      'report_timezone'=>self::sanitize_timezone((string) ($input['report_timezone']??$d['report_timezone'])),
      'ticket_keywords'=>self::sanitize_line_list((string) ($input['ticket_keywords']??$d['ticket_keywords'])),
      'exclude_keywords'=>self::sanitize_line_list((string) ($input['exclude_keywords']??$d['exclude_keywords'])),
      'film_mappings'=>self::sanitize_mappings((string) ($input['film_mappings']??$d['film_mappings'])),
      'studio_mappings'=>self::sanitize_studio_mappings((string) ($input['studio_mappings']??$d['studio_mappings'])),
      'recipient_emails'=>self::sanitize_email_list((string) ($input['recipient_emails']??$d['recipient_emails'])),
      'advertiser_emails'=>self::sanitize_email_list((string) ($input['advertiser_emails']??$d['advertiser_emails'])),
      'live_email_recipients'=>self::sanitize_email_list((string) ($input['live_email_recipients']??$d['live_email_recipients'])),
      'admin_email'=>sanitize_email((string) ($input['admin_email']??$d['admin_email'])),
      'email_subject'=>sanitize_text_field((string) ($input['email_subject']??$d['email_subject'])),
      'email_body'=>sanitize_textarea_field((string) ($input['email_body']??$d['email_body'])),
      'advertiser_email_subject'=>sanitize_text_field((string) ($input['advertiser_email_subject']??$d['advertiser_email_subject'])),
      'advertiser_email_body'=>sanitize_textarea_field((string) ($input['advertiser_email_body']??$d['advertiser_email_body'])),
      'theater_name'=>sanitize_text_field((string) ($input['theater_name']??$d['theater_name'])),
      'general_price'=>wc_format_decimal((string) ($input['general_price']??$d['general_price'])),
      'discount_price'=>wc_format_decimal((string) ($input['discount_price']??$d['discount_price'])),
      'group_price'=>wc_format_decimal((string) ($input['group_price']??$d['group_price'])),
      'lookback_days'=>(string) max(0,(int) ($input['lookback_days']??$d['lookback_days'])),
      'schedule_enabled'=>!empty($input['schedule_enabled'])?'1':'0',
      'schedule_time'=>self::sanitize_time((string) ($input['schedule_time']??$d['schedule_time'])),
      'advertiser_schedule_enabled'=>!empty($input['advertiser_schedule_enabled'])?'1':'0',
      'advertiser_schedule_day'=>(string) min(31,max(1,(int) ($input['advertiser_schedule_day']??$d['advertiser_schedule_day']))),
      'advertiser_schedule_time'=>self::sanitize_time((string) ($input['advertiser_schedule_time']??$d['advertiser_schedule_time'])),
    ];
    $workbook_template_path = array_key_exists('workbook_template_path', $input)
      ? sanitize_text_field((string) ($input['workbook_template_path'] ?? ''))
      : sanitize_text_field((string) ($existing['workbook_template_path'] ?? $d['workbook_template_path']));
    if ($workbook_template_path !== '') {
      $sanitized['workbook_template_path'] = $workbook_template_path;
    }
    $uploaded_template = $existing[self::WORKBOOK_TEMPLATE_UPLOAD_KEY] ?? [];
    if (is_array($uploaded_template) && $uploaded_template) {
      $sanitized[self::WORKBOOK_TEMPLATE_UPLOAD_KEY] = [
        'path' => sanitize_text_field((string) ($uploaded_template['path'] ?? '')),
        'url' => esc_url_raw((string) ($uploaded_template['url'] ?? '')),
        'name' => sanitize_file_name((string) ($uploaded_template['name'] ?? '')),
        'uploaded_at' => sanitize_text_field((string) ($uploaded_template['uploaded_at'] ?? '')),
      ];
    }
    Scheduler::sync_schedule($sanitized); return $sanitized;
  }

  public static function render_field(array $args): void {
    $key=(string) ($args['key']??''); $value=self::get($key,''); $name=self::OPTION_KEY.'['.$key.']';
    switch($key){
      case 'square_environment':
        echo '<select name="'.esc_attr($name).'">';
        foreach(['production'=>'Production','sandbox'=>'Sandbox'] as $ov=>$label){ echo '<option value="'.esc_attr($ov).'" '.selected($value,$ov,false).'>'.esc_html($label).'</option>'; }
        echo '</select><p class="description">Use sandbox only for testing with a Square sandbox token.</p>'; return;
      case 'square_access_token':
        echo '<input type="password" class="regular-text code" name="'.esc_attr($name).'" value="" autocomplete="off"'.(self::has_square_access_token() ? ' placeholder="Token saved - leave blank to keep current token"' : '').'><p class="description">Personal access token or OAuth token with Orders read access. Leave blank to keep the current token.</p>'; return;
      case 'square_location_ids': case 'ticket_keywords': case 'exclude_keywords': case 'film_mappings': case 'studio_mappings': case 'email_body': case 'advertiser_email_body':
        $rows=$key==='film_mappings'?'8':'5';
        echo '<textarea class="large-text code" rows="'.esc_attr($rows).'" name="'.esc_attr($name).'">'.esc_textarea((string) $value).'</textarea>';
        if($key==='square_location_ids') echo '<p class="description">One Square location ID per line. Square requires at least one location ID for order searches.</p>';
        elseif($key==='ticket_keywords') echo '<p class="description">One keyword per line. A line item must match at least one keyword to count as a ticket.</p>';
        elseif($key==='exclude_keywords') echo '<p class="description">One keyword per line. Matching line items are always ignored.</p>';
        elseif($key==='film_mappings') echo '<p class="description">One mapping per line in the format: match text|Comscore title|Film code.</p>';
        elseif($key==='studio_mappings') echo '<p class="description">Optional overrides in the format: match text|Studio. Overrides looked-up studio values.</p>';
        elseif($key==='email_body') echo '<p class="description">You can use {report_date}, {theater_name}, {gross_total}, and {ticket_total}.</p>';
          else echo '<p class="description">You can use {theater_name}, {month_name}, {month}, {year}, {attendance_total}, {gross_total}, {period_label}, {start_month}, and {end_month}.</p>';
        return;
      case 'recipient_emails': case 'advertiser_emails': case 'live_email_recipients': case 'admin_email': case 'email_subject': case 'advertiser_email_subject': case 'theater_name': case 'report_timezone': case 'lookback_days': case 'advertiser_schedule_day':
        $class=in_array($key,['recipient_emails','advertiser_emails','live_email_recipients'],true)?'regular-text code':'regular-text';
        echo '<input type="text" class="'.esc_attr($class).'" name="'.esc_attr($name).'" value="'.esc_attr((string) $value).'">';
        if($key==='recipient_emails'||$key==='advertiser_emails'||$key==='live_email_recipients') echo '<p class="description">Comma-separated email addresses.</p>';
        elseif($key==='admin_email') echo '<p class="description">Used for failure alerts when a run, workbook build, or email send fails.</p>';
        elseif($key==='report_timezone') echo '<p class="description">Timezone used to decide the report day and Square date window, for example America/Los_Angeles.</p>';
        elseif($key==='lookback_days') echo '<p class="description">How many previous days with the same film to refresh alongside the selected date. Use 0 to only update the selected day.</p>';
        elseif($key==='advertiser_schedule_day') echo '<p class="description">Day of the month to send last month\'s advertiser summary.</p>';
        return;
      case 'general_price': case 'discount_price': case 'group_price':
        echo '<input type="number" min="0" step="0.01" class="regular-text" name="'.esc_attr($name).'" value="'.esc_attr((string) $value).'">'; return;
      case 'schedule_enabled': case 'advertiser_schedule_enabled':
        $label=$key==='schedule_enabled'?'Run grosses automation automatically every day':'Send advertiser summary automatically each month';
        echo '<label><input type="checkbox" name="'.esc_attr($name).'" value="1" '.checked($value,'1',false).'> '.esc_html($label).'</label>'; return;
      case 'schedule_time': case 'advertiser_schedule_time':
        echo '<input type="time" name="'.esc_attr($name).'" value="'.esc_attr((string) $value).'">'; return;
    }
  }

  public static function render_page(): void {
    if(!roxy_suite_user_can_access_admin()) return;
    $status=self::get_status(); $timezone=new \DateTimeZone(self::get_report_timezone()); $default_date=wp_date('Y-m-d',null,$timezone); $default_advertiser_date=wp_date('Y-m-d',null,$timezone);
    $tab=self::current_tab(); $logs=Store::list_logs(100);
    echo '<div class="wrap"><h1>Roxy Grosses</h1><nav class="nav-tab-wrapper" style="margin-bottom:16px;">';
    echo '<a class="nav-tab '.($tab==='database'?'nav-tab-active':'').'" href="'.esc_url(admin_url('admin.php?page=roxy-grosses&tab=database')).'">Movies</a>';
    echo '<a class="nav-tab '.($tab==='live-shows'?'nav-tab-active':'').'" href="'.esc_url(admin_url('admin.php?page=roxy-grosses&tab=live-shows')).'">Live Shows</a>';
    echo '<a class="nav-tab '.($tab==='rentals'?'nav-tab-active':'').'" href="'.esc_url(admin_url('admin.php?page=roxy-grosses&tab=rentals')).'">Rentals</a>';
    echo '<a class="nav-tab '.($tab==='legacy-weekly'?'nav-tab-active':'').'" href="'.esc_url(admin_url('admin.php?page=roxy-grosses&tab=legacy-weekly')).'">Legacy Movies</a>';
    echo '<a class="nav-tab '.($tab==='workbook'?'nav-tab-active':'').'" href="'.esc_url(admin_url('admin.php?page=roxy-grosses&tab=workbook')).'">Workbook</a>';
    echo '<a class="nav-tab '.($tab==='daily'?'nav-tab-active':'').'" href="'.esc_url(admin_url('admin.php?page=roxy-grosses&tab=daily')).'">Saved Reports</a>';
    echo '<a class="nav-tab '.($tab==='settings'?'nav-tab-active':'').'" href="'.esc_url(admin_url('admin.php?page=roxy-grosses&tab=settings')).'">Settings</a>';
    echo '<a class="nav-tab '.($tab==='logs'?'nav-tab-active':'').'" href="'.esc_url(admin_url('admin.php?page=roxy-grosses&tab=logs')).'">Logs</a></nav>';
    if(!empty($_GET['roxy_grosses_notice'])){ $notice=sanitize_text_field(wp_unslash((string) $_GET['roxy_grosses_notice'])); $message=isset($_GET['message'])?sanitize_text_field(wp_unslash((string) $_GET['message'])):''; echo '<div class="'.esc_attr($notice==='success'?'notice notice-success':'notice notice-error').'"><p>'.esc_html($message).'</p></div>'; }
    if($tab==='settings'){ echo '<form method="post" action="options.php">'; settings_fields(self::OPTION_KEY); do_settings_sections('roxy-grosses'); submit_button('Save Grosses Settings'); echo '</form>'; self::render_test_tools($default_date,$default_advertiser_date); self::render_documentation_panel(); }
    elseif($tab==='live-shows'){ self::render_live_shows_tab($default_date); }
    elseif($tab==='rentals'){ self::render_rentals_tab(); }
    elseif($tab==='logs'){ self::render_logs_tab($logs); }
    elseif($tab==='legacy-weekly'){ self::render_legacy_weekly_tab(); }
    elseif($tab==='workbook'){ self::render_workbook_tab(self::workbook_year(),self::default_advertiser_month($timezone)); }
    elseif($tab==='daily'){ self::render_reports_tab(); }
    else { self::render_database_tab($default_date); }
    echo '</div>';
  }

  private static function workbook_year(): int {
    return isset($_GET['workbook_year']) ? max(2000, (int) $_GET['workbook_year']) : (int) wp_date('Y', null, new \DateTimeZone(self::get_report_timezone()));
  }

  private static function default_advertiser_month(\DateTimeZone $timezone): string {
    return (new \DateTimeImmutable('now', $timezone))->modify('first day of last month')->format('Y-m');
  }

  private static function render_database_tab(string $default_date): void {
    $per_page = 100;
    $search = isset($_GET['search']) ? sanitize_text_field(wp_unslash((string) $_GET['search'])) : '';
    $year = isset($_GET['history_year']) ? max(0, (int) $_GET['history_year']) : 0;
    $month = isset($_GET['history_month']) ? sanitize_text_field(wp_unslash((string) $_GET['history_month'])) : '';
    $day = isset($_GET['history_day']) ? sanitize_text_field(wp_unslash((string) $_GET['history_day'])) : '';
    $date_from = isset($_GET['history_from']) ? sanitize_text_field(wp_unslash((string) $_GET['history_from'])) : '';
    $date_to = isset($_GET['history_to']) ? sanitize_text_field(wp_unslash((string) $_GET['history_to'])) : '';
    $page_number = isset($_GET['movie_paged']) ? max(1, (int) $_GET['movie_paged']) : 1;
    $filters = array_filter([
      'search' => $search,
      'year' => $year ?: null,
      'month' => $month,
      'day' => $day,
      'date_from' => $date_from,
      'date_to' => $date_to,
    ]);
    $summary = Store::entries_summary($filters);
    $rows = Store::list_entries($filters, $per_page, ($page_number - 1) * $per_page);
    $years = Store::distinct_years();
    $edit_id = self::editing_row_id('movies');

    echo '<h2>Movies</h2><p>The movie grosses database is the source of truth. Pull from Square, then search by movie title, studio, genre, year, month, or day.</p>';
    echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'" style="display:flex; gap:12px; align-items:end; flex-wrap:wrap; margin-bottom:16px;">';
    wp_nonce_field('roxy_grosses_pull_database');
    echo '<input type="hidden" name="action" value="roxy_grosses_pull_database">';
    echo '<div><label for="roxy-grosses-pull-date"><strong>Manual pull date</strong></label><br><input id="roxy-grosses-pull-date" type="date" name="report_date" value="'.esc_attr($default_date).'"></div>';
    submit_button('Pull From Square','primary','submit',false);
    echo '</form>';

    echo '<form method="get" action="'.esc_url(admin_url('admin.php')).'" style="display:flex; gap:12px; align-items:end; flex-wrap:wrap; margin-bottom:16px;">';
    echo '<input type="hidden" name="page" value="roxy-grosses"><input type="hidden" name="tab" value="database">';
    echo '<div><label><strong>Search</strong></label><br><input type="text" class="regular-text" name="search" value="'.esc_attr($search).'" placeholder="Movie, studio, or genre"></div>';
    echo '<div><label><strong>From</strong></label><br><input type="date" name="history_from" value="'.esc_attr($date_from).'"></div>';
    echo '<div><label><strong>To</strong></label><br><input type="date" name="history_to" value="'.esc_attr($date_to).'"></div>';
    echo '<div><label><strong>Year</strong></label><br><select name="history_year"><option value="0">All years</option>';
    foreach ($years as $option_year) { echo '<option value="'.esc_attr((string) $option_year).'" '.selected($year, $option_year, false).'>'.esc_html((string) $option_year).'</option>'; }
    echo '</select></div>';
    echo '<div><label><strong>Month</strong></label><br><input type="month" name="history_month" value="'.esc_attr($month).'"></div>';
    echo '<div><label><strong>Day</strong></label><br><input type="date" name="history_day" value="'.esc_attr($day).'"></div>';
    submit_button('Filter','secondary','',false);
    echo '</form>';
    self::render_filter_presets('movies', [
      'tab' => 'database',
      'search' => $search,
    ]);
    self::render_export_form('movies', [
      'search' => $search,
      'history_from' => $date_from,
      'history_to' => $date_to,
      'history_year' => $year,
      'history_month' => $month,
      'history_day' => $day,
    ]);

    echo '<div style="display:flex; gap:16px; flex-wrap:wrap; margin:16px 0;">';
    foreach ([
      ['Rows', number_format_i18n((int) ($summary['row_count'] ?? 0))],
      ['Admissions', number_format_i18n((int) ($summary['admissions'] ?? 0))],
      ['Ticket Gross', '$' . number_format((float) ($summary['gross'] ?? 0), 2)],
      ['Concessions Gross', '$' . number_format((float) ($summary['concessions'] ?? 0), 2)],
      ['Avg Ticket Gross / Row', '$' . number_format((float) ($summary['average_gross'] ?? 0), 2)],
      ['Avg Concessions / Row', '$' . number_format((float) ($summary['average_concessions'] ?? 0), 2)],
    ] as $card) {
      echo '<div style="min-width:200px; padding:16px; background:#fff; border:1px solid #dcdcde; border-radius:4px;"><div style="font-size:12px; color:#50575e; text-transform:uppercase;">'.esc_html($card[0]).'</div><div style="font-size:24px; font-weight:600; margin-top:8px;">'.esc_html($card[1]).'</div></div>';
    }
    echo '</div>';

    echo '<table class="widefat striped"><thead><tr><th>Date</th><th>Movie</th><th>Studio</th><th>Genre</th><th>Show Time</th><th>Total</th><th>General</th><th>Discount</th><th>Group</th><th>Free</th><th>Gross</th><th>Concessions</th><th>Actions</th></tr></thead><tbody>';
    foreach ($rows as $row) {
      $row_id = (int) ($row['id'] ?? 0);
      echo '<tr><td>'.esc_html((string) ($row['report_date'] ?? '')).'</td><td>'.esc_html((string) ($row['movie_title'] ?? '')).'</td><td>'.esc_html((string) ($row['studio'] ?? '')).'</td><td>'.esc_html((string) ($row['genre'] ?? '')).'</td><td>'.esc_html((string) ($row['show_time'] ?? '')).'</td><td>'.esc_html(number_format_i18n((int) ($row['total_tickets'] ?? 0))).'</td><td>'.esc_html(number_format_i18n((int) ($row['general_qty'] ?? 0))).'</td><td>'.esc_html(number_format_i18n((int) ($row['discount_qty'] ?? 0))).'</td><td>'.esc_html(number_format_i18n((int) ($row['group_qty'] ?? 0))).'</td><td>'.esc_html(number_format_i18n((int) ($row['live_qty'] ?? 0))).'</td><td>$'.esc_html(number_format((float) ($row['gross_total'] ?? 0), 2)).'</td><td>$'.esc_html(number_format((float) ($row['concessions_total'] ?? 0), 2)).'</td><td><a class="button button-small" href="'.esc_url(self::edit_url('movies', $row_id, [
        'tab' => 'database',
        'search' => $search,
        'history_from' => $date_from,
        'history_to' => $date_to,
        'history_year' => $year ?: null,
        'history_month' => $month,
        'history_day' => $day,
      ])).'">Edit</a></td></tr>';
      if ($edit_id === $row_id) {
        self::render_movie_edit_row($row, $search, $year, $month, $day, $date_from, $date_to);
      }
    }
    if (!$rows) echo '<tr><td colspan="13">No grosses rows match the current filters.</td></tr>';
    echo '</tbody></table>';
    self::render_pagination($summary['row_count'] ?? 0, $per_page, $page_number, 'movie_paged', [
      'page' => 'roxy-grosses',
      'tab' => 'database',
      'search' => $search,
      'history_from' => $date_from,
      'history_to' => $date_to,
      'history_year' => $year ?: null,
      'history_month' => $month,
      'history_day' => $day,
    ]);
  }

  private static function render_live_shows_tab(string $default_date): void {
    $per_page = 100;
    $search = isset($_GET['live_search']) ? sanitize_text_field(wp_unslash((string) $_GET['live_search'])) : '';
    $year = isset($_GET['live_year']) ? max(0, (int) $_GET['live_year']) : 0;
    $month = isset($_GET['live_month']) ? sanitize_text_field(wp_unslash((string) $_GET['live_month'])) : '';
    $day = isset($_GET['live_day']) ? sanitize_text_field(wp_unslash((string) $_GET['live_day'])) : '';
    $date_from = isset($_GET['live_from']) ? sanitize_text_field(wp_unslash((string) $_GET['live_from'])) : '';
    $date_to = isset($_GET['live_to']) ? sanitize_text_field(wp_unslash((string) $_GET['live_to'])) : '';
    $page_number = isset($_GET['live_paged']) ? max(1, (int) $_GET['live_paged']) : 1;
    $filters = array_filter([
      'search' => $search,
      'year' => $year ?: null,
      'month' => $month,
      'day' => $day,
      'date_from' => $date_from,
      'date_to' => $date_to,
    ]);
    $summary = Store::live_entries_summary($filters);
    $rows = Store::list_live_entries($filters, $per_page, ($page_number - 1) * $per_page);
    $years = Store::distinct_live_years();
    $edit_id = self::editing_row_id('live');

    echo '<h2>Live Shows</h2><p>Track live show attendance and gross with online tickets from Show Tickets and door tickets from Square.</p>';
    echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'" style="display:flex; gap:12px; align-items:end; flex-wrap:wrap; margin-bottom:16px;">';
    wp_nonce_field('roxy_grosses_pull_live_database');
    echo '<input type="hidden" name="action" value="roxy_grosses_pull_live_database">';
    echo '<div><label for="roxy-grosses-live-pull-date"><strong>Manual pull date</strong></label><br><input id="roxy-grosses-live-pull-date" type="date" name="report_date" value="'.esc_attr($default_date).'"></div>';
    submit_button('Pull Live Shows','primary','submit',false);
    echo '</form>';

    echo '<form method="get" action="'.esc_url(admin_url('admin.php')).'" style="display:flex; gap:12px; align-items:end; flex-wrap:wrap; margin-bottom:16px;">';
    echo '<input type="hidden" name="page" value="roxy-grosses"><input type="hidden" name="tab" value="live-shows">';
    echo '<div><label><strong>Search</strong></label><br><input type="text" class="regular-text" name="live_search" value="'.esc_attr($search).'" placeholder="Show title"></div>';
    echo '<div><label><strong>From</strong></label><br><input type="date" name="live_from" value="'.esc_attr($date_from).'"></div>';
    echo '<div><label><strong>To</strong></label><br><input type="date" name="live_to" value="'.esc_attr($date_to).'"></div>';
    echo '<div><label><strong>Year</strong></label><br><select name="live_year"><option value="0">All years</option>';
    foreach ($years as $option_year) { echo '<option value="'.esc_attr((string) $option_year).'" '.selected($year, $option_year, false).'>'.esc_html((string) $option_year).'</option>'; }
    echo '</select></div>';
    echo '<div><label><strong>Month</strong></label><br><input type="month" name="live_month" value="'.esc_attr($month).'"></div>';
    echo '<div><label><strong>Day</strong></label><br><input type="date" name="live_day" value="'.esc_attr($day).'"></div>';
    submit_button('Filter','secondary','',false);
    echo '</form>';
    self::render_filter_presets('live', [
      'tab' => 'live-shows',
      'live_search' => $search,
    ]);
    self::render_export_form('live', [
      'live_search' => $search,
      'live_from' => $date_from,
      'live_to' => $date_to,
      'live_year' => $year,
      'live_month' => $month,
      'live_day' => $day,
    ]);

    echo '<div style="display:flex; gap:16px; flex-wrap:wrap; margin:16px 0;">';
    foreach ([
      ['Rows', number_format_i18n((int) ($summary['row_count'] ?? 0))],
      ['Attendance', number_format_i18n((int) ($summary['admissions'] ?? 0))],
      ['Ticket Gross', '$' . number_format((float) ($summary['gross'] ?? 0), 2)],
      ['Concessions Gross', '$' . number_format((float) ($summary['concessions'] ?? 0), 2)],
      ['Avg Ticket Gross / Row', '$' . number_format((float) ($summary['average_gross'] ?? 0), 2)],
      ['Avg Concessions / Row', '$' . number_format((float) ($summary['average_concessions'] ?? 0), 2)],
    ] as $card) {
      echo '<div style="min-width:200px; padding:16px; background:#fff; border:1px solid #dcdcde; border-radius:4px;"><div style="font-size:12px; color:#50575e; text-transform:uppercase;">'.esc_html($card[0]).'</div><div style="font-size:24px; font-weight:600; margin-top:8px;">'.esc_html($card[1]).'</div></div>';
    }
    echo '</div>';

    echo '<table class="widefat striped"><thead><tr><th>Date</th><th>Show</th><th>Show Time</th><th>Total</th><th>Presale Tickets</th><th>Online Ticket</th><th>Door Ticket</th><th>Group/Subscriber</th><th>Gross</th><th>Concessions</th><th>Actions</th></tr></thead><tbody>';
    foreach ($rows as $row) {
      $row_id = (int) ($row['id'] ?? 0);
      echo '<tr><td>'.esc_html((string) ($row['report_date'] ?? '')).'</td><td>'.esc_html((string) ($row['show_title'] ?? '')).'</td><td>'.esc_html((string) ($row['show_time'] ?? '')).'</td><td>'.esc_html(number_format_i18n((int) ($row['total_tickets'] ?? 0))).'</td><td>'.esc_html(number_format_i18n((int) ($row['presale_qty'] ?? 0))).'</td><td>'.esc_html(number_format_i18n((int) ($row['online_qty'] ?? 0))).'</td><td>'.esc_html(number_format_i18n((int) ($row['door_qty'] ?? 0))).'</td><td>'.esc_html(number_format_i18n((int) ($row['group_sub_qty'] ?? 0))).'</td><td>$'.esc_html(number_format((float) ($row['gross_total'] ?? 0), 2)).'</td><td>$'.esc_html(number_format((float) ($row['concessions_total'] ?? 0), 2)).'</td><td><a class="button button-small" href="'.esc_url(self::edit_url('live', $row_id, [
        'tab' => 'live-shows',
        'live_search' => $search,
        'live_from' => $date_from,
        'live_to' => $date_to,
        'live_year' => $year ?: null,
        'live_month' => $month,
        'live_day' => $day,
      ])).'">Edit</a></td></tr>';
      if ($edit_id === $row_id) {
        self::render_live_edit_row($row, $search, $year, $month, $day, $date_from, $date_to);
      }
    }
    if (!$rows) echo '<tr><td colspan="11">No live show rows match the current filters.</td></tr>';
    echo '</tbody></table>';
    self::render_pagination($summary['row_count'] ?? 0, $per_page, $page_number, 'live_paged', [
      'page' => 'roxy-grosses',
      'tab' => 'live-shows',
      'live_search' => $search,
      'live_from' => $date_from,
      'live_to' => $date_to,
      'live_year' => $year ?: null,
      'live_month' => $month,
      'live_day' => $day,
    ]);
  }

  private static function render_rentals_tab(): void {
    $per_page = 100;
    $search = isset($_GET['rental_search']) ? sanitize_text_field(wp_unslash((string) $_GET['rental_search'])) : '';
    $year = isset($_GET['rental_year']) ? max(0, (int) $_GET['rental_year']) : 0;
    $month = isset($_GET['rental_month']) ? sanitize_text_field(wp_unslash((string) $_GET['rental_month'])) : '';
    $day = isset($_GET['rental_day']) ? sanitize_text_field(wp_unslash((string) $_GET['rental_day'])) : '';
    $date_from = isset($_GET['rental_from']) ? sanitize_text_field(wp_unslash((string) $_GET['rental_from'])) : '';
    $date_to = isset($_GET['rental_to']) ? sanitize_text_field(wp_unslash((string) $_GET['rental_to'])) : '';
    $page_number = isset($_GET['rental_paged']) ? max(1, (int) $_GET['rental_paged']) : 1;
    $filters = array_filter([
      'search' => $search,
      'year' => $year ?: null,
      'month' => $month,
      'day' => $day,
      'date_from' => $date_from,
      'date_to' => $date_to,
    ]);
    $rows = Store::list_rental_entries($filters, $per_page, ($page_number - 1) * $per_page);
    $summary = Store::rental_entries_summary($filters);
    $years = Store::distinct_rental_years();
    $edit_id = self::editing_row_id('rentals');

    echo '<h2>Rentals</h2><p>This tab stores private rentals, field trips, reward days, and other invoice-backed special bookings separately from movies and public live shows.</p>';
    echo '<form method="get" action="'.esc_url(admin_url('admin.php')).'" style="display:flex; gap:12px; align-items:end; flex-wrap:wrap; margin-bottom:16px;">';
    echo '<input type="hidden" name="page" value="roxy-grosses"><input type="hidden" name="tab" value="rentals">';
    echo '<div><label><strong>Search</strong></label><br><input type="text" class="regular-text" name="rental_search" value="'.esc_attr($search).'" placeholder="Rental title or customer"></div>';
    echo '<div><label><strong>From</strong></label><br><input type="date" name="rental_from" value="'.esc_attr($date_from).'"></div>';
    echo '<div><label><strong>To</strong></label><br><input type="date" name="rental_to" value="'.esc_attr($date_to).'"></div>';
    echo '<div><label><strong>Year</strong></label><br><select name="rental_year"><option value="0">All years</option>';
    foreach ($years as $option_year) { echo '<option value="'.esc_attr((string) $option_year).'" '.selected($year, $option_year, false).'>'.esc_html((string) $option_year).'</option>'; }
    echo '</select></div>';
    echo '<div><label><strong>Month</strong></label><br><input type="month" name="rental_month" value="'.esc_attr($month).'"></div>';
    echo '<div><label><strong>Day</strong></label><br><input type="date" name="rental_day" value="'.esc_attr($day).'"></div>';
    submit_button('Filter','secondary','',false);
    echo '</form>';
    self::render_filter_presets('rentals', [
      'tab' => 'rentals',
      'rental_search' => $search,
    ]);
    self::render_export_form('rentals', [
      'rental_search' => $search,
      'rental_from' => $date_from,
      'rental_to' => $date_to,
      'rental_year' => $year,
      'rental_month' => $month,
      'rental_day' => $day,
    ]);

    echo '<div style="display:flex; gap:16px; flex-wrap:wrap; margin:16px 0;">';
    foreach ([
      ['Rows', number_format_i18n((int) ($summary['row_count'] ?? 0))],
      ['Invoice Gross', '$' . number_format((float) ($summary['invoice_total'] ?? 0), 2)],
      ['Concessions Gross', '$' . number_format((float) ($summary['concessions'] ?? 0), 2)],
      ['Avg Invoice / Row', '$' . number_format((float) ($summary['average_invoice'] ?? 0), 2)],
      ['Avg Concessions / Row', '$' . number_format((float) ($summary['average_concessions'] ?? 0), 2)],
    ] as $card) {
      echo '<div style="min-width:200px; padding:16px; background:#fff; border:1px solid #dcdcde; border-radius:4px;"><div style="font-size:12px; color:#50575e; text-transform:uppercase;">'.esc_html($card[0]).'</div><div style="font-size:24px; font-weight:600; margin-top:8px;">'.esc_html($card[1]).'</div></div>';
    }
    echo '</div>';

    echo '<table class="widefat striped"><thead><tr><th>Date</th><th>Rental</th><th>Type</th><th>Customer</th><th>Status</th><th>Invoice</th><th>Concessions</th><th>Notes</th><th>Actions</th></tr></thead><tbody>';
    foreach ($rows as $row) {
      $row_id = (int) ($row['id'] ?? 0);
      echo '<tr><td>'.esc_html((string) ($row['report_date'] ?? '')).'</td><td>'.esc_html((string) ($row['rental_title'] ?? '')).'</td><td>'.esc_html((string) ($row['rental_type'] ?? '')).'</td><td>'.esc_html((string) ($row['customer_name'] ?? '')).'</td><td>'.esc_html((string) ($row['status'] ?? '')).'</td><td>$'.esc_html(number_format((float) ($row['invoice_amount'] ?? 0), 2)).'</td><td>$'.esc_html(number_format((float) ($row['concessions_total'] ?? 0), 2)).'</td><td>'.esc_html((string) ($row['notes'] ?? '')).'</td><td><a class="button button-small" href="'.esc_url(self::edit_url('rentals', $row_id, [
        'tab' => 'rentals',
        'rental_search' => $search,
        'rental_from' => $date_from,
        'rental_to' => $date_to,
        'rental_year' => $year ?: null,
        'rental_month' => $month,
        'rental_day' => $day,
      ])).'">Edit</a></td></tr>';
      if ($edit_id === $row_id) {
        self::render_rental_edit_row($row, $search, $year, $month, $day, $date_from, $date_to);
      }
    }
    if (!$rows) echo '<tr><td colspan="9">No rentals match the current filters.</td></tr>';
    echo '</tbody></table>';
    self::render_pagination($summary['row_count'] ?? 0, $per_page, $page_number, 'rental_paged', [
      'page' => 'roxy-grosses',
      'tab' => 'rentals',
      'rental_search' => $search,
      'rental_from' => $date_from,
      'rental_to' => $date_to,
      'rental_year' => $year ?: null,
      'rental_month' => $month,
      'rental_day' => $day,
    ]);
  }

  private static function render_legacy_weekly_tab(): void {
    $per_page = 100;
    $search = isset($_GET['legacy_search']) ? sanitize_text_field(wp_unslash((string) $_GET['legacy_search'])) : '';
    $year = isset($_GET['legacy_year']) ? max(0, (int) $_GET['legacy_year']) : 0;
    $month = isset($_GET['legacy_month']) ? sanitize_text_field(wp_unslash((string) $_GET['legacy_month'])) : '';
    $day = isset($_GET['legacy_day']) ? sanitize_text_field(wp_unslash((string) $_GET['legacy_day'])) : '';
    $date_from = isset($_GET['legacy_from']) ? sanitize_text_field(wp_unslash((string) $_GET['legacy_from'])) : '';
    $date_to = isset($_GET['legacy_to']) ? sanitize_text_field(wp_unslash((string) $_GET['legacy_to'])) : '';
    $page_number = isset($_GET['legacy_paged']) ? max(1, (int) $_GET['legacy_paged']) : 1;
    $filters = array_filter([
      'search' => $search,
      'year' => $year ?: null,
      'month' => $month,
      'day' => $day,
      'date_from' => $date_from,
      'date_to' => $date_to,
    ]);
    $rows = Store::list_legacy_weekly($filters, $per_page, ($page_number - 1) * $per_page);
    $summary = Store::legacy_weekly_summary($filters);
    $years = Store::distinct_legacy_weekly_years();
    $edit_id = self::editing_row_id('legacy');

    echo '<h2>Legacy Movies</h2><p>This tab stores the previous owner\'s weekly attendance workbook as a separate historical dataset. It is weekly, multi-theater legacy data and does not affect the modern daily movie database.</p>';
    echo '<form method="get" action="'.esc_url(admin_url('admin.php')).'" style="display:flex; gap:12px; align-items:end; flex-wrap:wrap; margin-bottom:16px;">';
    echo '<input type="hidden" name="page" value="roxy-grosses"><input type="hidden" name="tab" value="legacy-weekly">';
    echo '<div><label><strong>Search</strong></label><br><input type="text" class="regular-text" name="legacy_search" value="'.esc_attr($search).'" placeholder="Movie title"></div>';
    echo '<div><label><strong>From</strong></label><br><input type="date" name="legacy_from" value="'.esc_attr($date_from).'"></div>';
    echo '<div><label><strong>To</strong></label><br><input type="date" name="legacy_to" value="'.esc_attr($date_to).'"></div>';
    echo '<div><label><strong>Year</strong></label><br><select name="legacy_year"><option value="0">All years</option>';
    foreach ($years as $option_year) { echo '<option value="'.esc_attr((string) $option_year).'" '.selected($year, $option_year, false).'>'.esc_html((string) $option_year).'</option>'; }
    echo '</select></div>';
    echo '<div><label><strong>Month</strong></label><br><input type="month" name="legacy_month" value="'.esc_attr($month).'"></div>';
    echo '<div><label><strong>Week Of</strong></label><br><input type="date" name="legacy_day" value="'.esc_attr($day).'"></div>';
    submit_button('Filter','secondary','',false);
    echo '</form>';
    self::render_filter_presets('legacy', [
      'tab' => 'legacy-weekly',
      'legacy_search' => $search,
    ]);
    self::render_export_form('legacy', [
      'legacy_search' => $search,
      'legacy_from' => $date_from,
      'legacy_to' => $date_to,
      'legacy_year' => $year,
      'legacy_month' => $month,
      'legacy_day' => $day,
    ]);

    echo '<div style="display:flex; gap:16px; flex-wrap:wrap; margin:16px 0;">';
    foreach ([
      ['Rows', number_format_i18n((int) ($summary['row_count'] ?? 0))],
      ['Attendance', number_format_i18n((int) ($summary['attendance'] ?? 0))],
      ['Ticket Gross', '$' . number_format((float) ($summary['gross'] ?? 0), 2)],
      ['Concessions Gross', '$' . number_format((float) ($summary['concessions'] ?? 0), 2)],
      ['Avg Ticket Gross / Row', '$' . number_format((float) ($summary['average_gross'] ?? 0), 2)],
      ['Avg Concessions / Row', '$' . number_format((float) ($summary['average_concessions'] ?? 0), 2)],
    ] as $card) {
      echo '<div style="min-width:200px; padding:16px; background:#fff; border:1px solid #dcdcde; border-radius:4px;"><div style="font-size:12px; color:#50575e; text-transform:uppercase;">'.esc_html($card[0]).'</div><div style="font-size:24px; font-weight:600; margin-top:8px;">'.esc_html($card[1]).'</div></div>';
    }
    echo '</div>';

    echo '<table class="widefat striped"><thead><tr><th>Week Of</th><th>Week End</th><th>Movie</th><th>Rating</th><th>Weeks</th><th>General</th><th>Discount</th><th>Free</th><th>Total</th><th>Ticket Gross</th><th>Concessions</th><th>Actions</th></tr></thead><tbody>';
    foreach ($rows as $row) {
      $row_id = (int) ($row['id'] ?? 0);
      echo '<tr><td>'.esc_html((string) ($row['week_start_date'] ?? '')).'</td><td>'.esc_html((string) ($row['week_end_date'] ?? '')).'</td><td>'.esc_html((string) ($row['movie_title'] ?? '')).'</td><td>'.esc_html((string) ($row['rating'] ?? '')).'</td><td>'.esc_html((string) ($row['weeks_run'] ?? '')).'</td><td>'.esc_html(number_format_i18n((int) ($row['general_qty'] ?? 0))).'</td><td>'.esc_html(number_format_i18n((int) ($row['discount_qty'] ?? 0))).'</td><td>'.esc_html(number_format_i18n((int) ($row['free_qty'] ?? 0))).'</td><td>'.esc_html(number_format_i18n((int) ($row['total_attendance'] ?? 0))).'</td><td>$'.esc_html(number_format((float) ($row['gross_total'] ?? 0), 2)).'</td><td>$'.esc_html(number_format((float) ($row['concessions_total'] ?? 0), 2)).'</td><td><a class="button button-small" href="'.esc_url(self::edit_url('legacy', $row_id, [
        'tab' => 'legacy-weekly',
        'legacy_search' => $search,
        'legacy_from' => $date_from,
        'legacy_to' => $date_to,
        'legacy_year' => $year ?: null,
        'legacy_month' => $month,
        'legacy_day' => $day,
      ])).'">Edit</a></td></tr>';
      if ($edit_id === $row_id) {
        self::render_legacy_edit_row($row, $search, $year, $month, $day, $date_from, $date_to);
      }
    }
    if (!$rows) echo '<tr><td colspan="12">No legacy weekly rows match the current filters.</td></tr>';
    echo '</tbody></table>';
    self::render_pagination($summary['row_count'] ?? 0, $per_page, $page_number, 'legacy_paged', [
      'page' => 'roxy-grosses',
      'tab' => 'legacy-weekly',
      'legacy_search' => $search,
      'legacy_from' => $date_from,
      'legacy_to' => $date_to,
      'legacy_year' => $year ?: null,
      'legacy_month' => $month,
      'legacy_day' => $day,
    ]);
  }

  private static function render_pagination(int $total_rows, int $per_page, int $current_page, string $page_key, array $query_args): void {
    $total_pages = max(1, (int) ceil($total_rows / max(1, $per_page)));
    if ($total_pages <= 1) {
      return;
    }

    $base_args = array_filter($query_args, static function ($value) {
      return $value !== null && $value !== '';
    });
    unset($base_args[$page_key]);

    echo '<div class="tablenav"><div class="tablenav-pages" style="margin:12px 0 0 auto;">';
    echo paginate_links([
      'base' => add_query_arg(array_merge($base_args, [$page_key => '%#%']), admin_url('admin.php')),
      'format' => '',
      'prev_text' => '&laquo;',
      'next_text' => '&raquo;',
      'current' => $current_page,
      'total' => $total_pages,
    ]);
    echo '</div></div>';
  }

  private static function render_logs_tab(array $logs): void {
    $timezone = new \DateTimeZone(self::get_report_timezone());
    $default_to = wp_date('Y-m-d', null, $timezone);
    $default_from = wp_date('Y-m-d', time() - (29 * DAY_IN_SECONDS), $timezone);
    $rec_from = isset($_GET['reconciliation_from']) ? sanitize_text_field(wp_unslash((string) $_GET['reconciliation_from'])) : $default_from;
    $rec_to = isset($_GET['reconciliation_to']) ? sanitize_text_field(wp_unslash((string) $_GET['reconciliation_to'])) : $default_to;
    $reconciliation = Reporter::reconciliation_rows($rec_from, $rec_to);
    echo '<h2>Logs</h2><p>Review database pulls, daily email sends, advertiser sends, anomalies, and reconciliation gaps.</p>';
    echo '<h3>Reconciliation</h3><p>Compare Square In Store Purchase totals against the concessions currently assigned across Movies, Live Shows, and Rentals.</p>';
    echo '<form method="get" action="'.esc_url(admin_url('admin.php')).'" style="display:flex; gap:12px; align-items:end; flex-wrap:wrap; margin-bottom:16px;">';
    echo '<input type="hidden" name="page" value="roxy-grosses"><input type="hidden" name="tab" value="logs">';
    echo '<div><label><strong>From</strong></label><br><input type="date" name="reconciliation_from" value="'.esc_attr($rec_from).'"></div>';
    echo '<div><label><strong>To</strong></label><br><input type="date" name="reconciliation_to" value="'.esc_attr($rec_to).'"></div>';
    submit_button('Run Reconciliation','secondary','',false);
    echo '</form>';
    echo '<table class="widefat striped" style="margin-bottom:24px;"><thead><tr><th>Date</th><th>Square</th><th>Movies</th><th>Live Shows</th><th>Rentals</th><th>Assigned</th><th>Difference</th></tr></thead><tbody>';
    foreach ($reconciliation as $row) {
      echo '<tr><td>'.esc_html((string) $row['date']).'</td><td>$'.esc_html(number_format((float) $row['square_total'], 2)).'</td><td>$'.esc_html(number_format((float) $row['movies'], 2)).'</td><td>$'.esc_html(number_format((float) $row['live'], 2)).'</td><td>$'.esc_html(number_format((float) $row['rentals'], 2)).'</td><td>$'.esc_html(number_format((float) $row['assigned_total'], 2)).'</td><td>$'.esc_html(number_format((float) $row['difference'], 2)).'</td></tr>';
    }
    if(!$reconciliation) echo '<tr><td colspan="7">No reconciliation differences were found in this date range.</td></tr>';
    echo '</tbody></table>';
    echo '<table class="widefat striped" style="max-width:1100px"><thead><tr><th>Time</th><th>Event</th><th>Mode</th><th>Report ID</th><th>End Date</th><th>Result</th><th>Message</th></tr></thead><tbody>';
    foreach($logs as $log_row){
      echo '<tr><td>'.esc_html((string) $log_row['created_at']).'</td><td>'.esc_html((string) $log_row['event_type']).'</td><td>'.esc_html((string) $log_row['mode']).'</td><td>'.esc_html(!empty($log_row['report_id'])?(string) $log_row['report_id']:'-').'</td><td>'.esc_html((string) ($log_row['report_end_date']?:'-')).'</td><td>'.(!empty($log_row['success'])?'Success':'Failed').'</td><td>'.esc_html((string) $log_row['message']).'</td></tr>';
    }
    if(!$logs) echo '<tr><td colspan="7">No log entries yet.</td></tr>'; echo '</tbody></table>';
  }

  private static function render_test_tools(string $default_date, string $default_advertiser_date): void {
    $timezone = new \DateTimeZone(self::get_report_timezone());
    $default_advertiser_end = (new \DateTimeImmutable($default_advertiser_date . ' 00:00:00', $timezone))->modify('first day of last month')->format('Y-m');
    $default_advertiser_start = $default_advertiser_end;
    $live_rows = Store::list_live_entries([], 200, 0);
    echo '<hr><h2>Email Tools</h2><p>Each email flow can send an admin-only test copy or the full production email to its saved recipient list.</p>';
    echo '<div style="display:flex; gap:24px; align-items:flex-start; flex-wrap:wrap;">';

    echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'" style="min-width:320px; padding:16px; background:#fff; border:1px solid #dcdcde; border-radius:4px;">';
    wp_nonce_field('roxy_grosses_send_manual');
    echo '<input type="hidden" name="action" value="roxy_grosses_send_manual">';
    echo '<input type="hidden" name="return_tab" value="settings">';
    echo '<h3 style="margin-top:0;">Send Daily Grosses Email</h3>';
    echo '<p>Select a report date, then send either an admin-only test email or the full daily grosses email to the saved daily recipient list.</p>';
    echo '<p><label for="roxy-grosses-test-date"><strong>Report date</strong></label><br><input id="roxy-grosses-test-date" type="date" name="report_date" value="'.esc_attr($default_date).'"></p>';
    echo '<div style="display:flex; gap:8px; flex-wrap:wrap;">';
    submit_button('Send Test Email','secondary','test_send',false,['value'=>'1']);
    submit_button('Send Daily Grosses Email','primary','submit',false);
    echo '</div>';
    echo '</form>';

    echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'" style="min-width:360px; padding:16px; background:#fff; border:1px solid #dcdcde; border-radius:4px;">';
    wp_nonce_field('roxy_grosses_send_advertiser_summary');
    echo '<input type="hidden" name="action" value="roxy_grosses_send_advertiser_summary">';
    echo '<input type="hidden" name="return_tab" value="settings">';
    echo '<h3 style="margin-top:0;">Send Advertiser Email</h3>';
    echo '<p>Select a month range to send. For a single month, choose the same start and end month. For the last 12 months, choose the first and last months in that span.</p>';
    echo '<p><label for="roxy-grosses-advertiser-start-month"><strong>Start month</strong></label><br><input id="roxy-grosses-advertiser-start-month" type="month" name="advertiser_start_month" value="'.esc_attr($default_advertiser_start).'"></p>';
    echo '<p><label for="roxy-grosses-advertiser-end-month"><strong>End month</strong></label><br><input id="roxy-grosses-advertiser-end-month" type="month" name="advertiser_end_month" value="'.esc_attr($default_advertiser_end).'"></p>';
    echo '<div style="display:flex; gap:8px; flex-wrap:wrap;">';
    submit_button('Send Test Email','secondary','test_send',false,['value'=>'1']);
    submit_button('Send Advertiser Email','primary','submit',false);
    echo '</div>';
    echo '</form>';

    echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'" style="min-width:380px; padding:16px; background:#fff; border:1px solid #dcdcde; border-radius:4px;">';
    wp_nonce_field('roxy_grosses_send_live_email');
    echo '<input type="hidden" name="action" value="roxy_grosses_send_live_email">';
    echo '<h3 style="margin-top:0;">Send Live Grosses Email</h3>';
    echo '<input type="hidden" name="return_tab" value="settings">';
    echo '<p>Select a live show row, then send either an admin-only test email or the full live grosses email to the saved live recipient list.</p>';
    echo '<p><label for="roxy-grosses-live-email-show"><strong>Live show</strong></label><br><select id="roxy-grosses-live-email-show" name="live_entry_id" style="width:100%; max-width:420px;">';
    echo '<option value="">Select a live show</option>';
    foreach ($live_rows as $row) {
      $label = trim((string) ($row['report_date'] ?? '') . ' ' . (string) ($row['show_time'] ?? '') . ' - ' . (string) ($row['show_title'] ?? ''));
      $label .= ' | Tickets ' . number_format_i18n((int) ($row['total_tickets'] ?? 0)) . ' | Gross $' . number_format((float) ($row['gross_total'] ?? 0), 2);
      echo '<option value="'.esc_attr((string) ($row['id'] ?? 0)).'">'.esc_html($label).'</option>';
    }
    echo '</select></p>';
    echo '<p><label><input type="checkbox" name="include_concessions" value="1"> Include concession sales</label></p>';
    echo '<div style="display:flex; gap:8px; flex-wrap:wrap;">';
    submit_button('Send Test Email','secondary','test_send',false,['value'=>'1']);
    submit_button('Send Live Grosses Email','primary','submit',false);
    echo '</div>';
    echo '</form>';

    echo '</div>';
  }

  private static function render_documentation_panel(): void {
    echo '<hr><h2>Grosses Notes</h2>';
    echo '<div style="display:flex; gap:24px; align-items:flex-start; flex-wrap:wrap;">';
    echo '<div style="min-width:360px; flex:1 1 420px; padding:16px; background:#fff; border:1px solid #dcdcde; border-radius:4px;">';
    echo '<h3 style="margin-top:0;">Operating Rules</h3>';
    echo '<ul style="list-style:disc; padding-left:20px; margin:0;">';
    echo '<li>Movies sync from the Showings plugin plus Square every day at the scheduled run time.</li>';
    echo '<li>Movie gross uses configured ticket prices, not Square dollar amounts.</li>';
    echo '<li>Free tickets are tracked in the database, included in advertiser attendance, and excluded from daily grosses totals.</li>';
    echo '<li>Movie and live-show concessions use Square <code>In Store Purchase</code> values.</li>';
    echo '<li>Live Shows combine online tickets from Show Tickets with door tickets from Square. Square prepaid lines are not counted as door sales.</li>';
    echo '<li>Rentals stay manual for now and can be imported periodically until that workflow is automated.</li>';
    echo '</ul>';
    echo '</div>';
    echo '</div>';
  }

  private static function render_workbook_tab(int $workbook_year,string $default_advertiser_month): void {
    $summary=Workbook::dashboard_summary($workbook_year); $monthly_rows=Workbook::monthly_totals($workbook_year); $weekly_rows=Workbook::weekly_rows_for_year($workbook_year); $snapshot=Workbook::get_snapshot_status($workbook_year); $template=Workbook::template_status();
    echo '<h2>Workbook Dashboard</h2><p>Review the yearly workbook data stored by the plugin, download the spreadsheet, and send the monthly advertiser summary.</p>';
    echo '<form method="get" action="'.esc_url(admin_url('admin.php')).'" style="margin-bottom:16px;"><input type="hidden" name="page" value="roxy-grosses"><input type="hidden" name="tab" value="workbook"><label for="roxy-grosses-workbook-year" style="margin-right:8px;"><strong>Year</strong></label><input id="roxy-grosses-workbook-year" type="number" min="2000" max="2100" name="workbook_year" value="'.esc_attr((string) $workbook_year).'">';
    submit_button('Load Year','secondary','',false,['style'=>'margin-left:8px;']); echo '</form><div style="display:flex; gap:16px; flex-wrap:wrap; margin:16px 0;">';
    foreach([['Annual Admissions',number_format_i18n((int) ($summary['annual_admissions']??0))],['Annual Gross','$'.number_format((float) ($summary['annual_gross']??0),2)],['Weeks Entered',number_format_i18n((int) ($summary['weeks_entered']??0))],['Average Gross / Entered Week','$'.number_format((float) ($summary['average_gross']??0),2)]] as $card){
      echo '<div style="min-width:220px; padding:16px; background:#fff; border:1px solid #dcdcde; border-radius:4px;"><div style="font-size:12px; color:#50575e; text-transform:uppercase;">'.esc_html($card[0]).'</div><div style="font-size:24px; font-weight:600; margin-top:8px;">'.esc_html($card[1]).'</div></div>';
    }
    echo '</div><p><strong>Template:</strong> '.esc_html((string) ($template['name']??'None uploaded')).' | <strong>Status:</strong> '.esc_html(!empty($template['readable'])?'Ready':'Missing').'</p><p><strong>Last workbook refresh:</strong> '.esc_html((string) ($snapshot['refreshed_at']??'Never')).'</p>';
    echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'" enctype="multipart/form-data" style="margin-bottom:16px;">'; wp_nonce_field('roxy_grosses_upload_template'); echo '<input type="hidden" name="action" value="roxy_grosses_upload_template"><label for="roxy-grosses-template-file" style="margin-right:8px;"><strong>Upload workbook template</strong></label><input id="roxy-grosses-template-file" type="file" name="workbook_template" accept=".xlsx">'; submit_button('Upload Template','secondary','submit',false,['style'=>'margin-left:8px;']); echo '</form><div style="display:flex; gap:12px; flex-wrap:wrap; margin-bottom:24px;">';
    echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">'; wp_nonce_field('roxy_grosses_refresh_workbook'); echo '<input type="hidden" name="action" value="roxy_grosses_refresh_workbook"><input type="hidden" name="year" value="'.esc_attr((string) $workbook_year).'">'; submit_button('Refresh Workbook Data','secondary','submit',false); echo '</form>';
    echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">'; wp_nonce_field('roxy_grosses_download_workbook'); echo '<input type="hidden" name="action" value="roxy_grosses_download_workbook"><input type="hidden" name="year" value="'.esc_attr((string) $workbook_year).'">'; submit_button('Download Excel Workbook','primary','submit',false); echo '</form>';
    echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'" style="display:flex; gap:8px; align-items:flex-end;">'; wp_nonce_field('roxy_grosses_send_advertiser_summary'); echo '<input type="hidden" name="action" value="roxy_grosses_send_advertiser_summary"><div><label for="roxy-grosses-advertiser-month"><strong>Advertiser month</strong></label><br><input id="roxy-grosses-advertiser-month" type="month" name="advertiser_month" value="'.esc_attr($default_advertiser_month).'"></div>'; submit_button('Send Advertiser Summary Now','secondary','submit',false); echo '</form></div>';
    echo '<h3>Monthly Totals</h3><table class="widefat striped" style="max-width:900px"><thead><tr><th>Month</th><th>Weeks</th><th>Admissions</th><th>Gross</th><th>Avg Gross / Week</th><th>Open Days</th></tr></thead><tbody>';
    foreach($monthly_rows as $row){ echo '<tr><td>'.esc_html((string) ($row['month_name']??'')).'</td><td>'.esc_html(number_format_i18n((int) ($row['weeks']??0))).'</td><td>'.esc_html(number_format_i18n((int) ($row['admissions']??0))).'</td><td>$'.esc_html(number_format((float) ($row['gross']??0),2)).'</td><td>$'.esc_html(number_format((float) ($row['average_gross']??0),2)).'</td><td>'.esc_html(number_format_i18n((int) ($row['open_days']??0))).'</td></tr>'; }
    echo '</tbody></table><h3 style="margin-top:24px;">Weekly Log Preview</h3><table class="widefat striped"><thead><tr><th>Week</th><th>Week Of</th><th>Film Title</th><th>Studio</th><th>Admissions</th><th>Gross</th><th>Open Days</th></tr></thead><tbody>';
    foreach($weekly_rows as $row){ echo '<tr><td>'.esc_html((string) ($row['week_number']??'')).'</td><td>'.esc_html((string) ($row['week_of']??'')).'</td><td>'.esc_html((string) ($row['film_title']??'')).'</td><td>'.esc_html((string) ($row['studio']??'')).'</td><td>'.esc_html(number_format_i18n((int) ($row['admissions']??0))).'</td><td>$'.esc_html(number_format((float) ($row['gross']??0),2)).'</td><td>'.esc_html(number_format_i18n((int) ($row['open_days']??0))).'</td></tr>'; }
    if(!$weekly_rows) echo '<tr><td colspan="7">No yearly workbook rows have been stored yet.</td></tr>'; echo '</tbody></table>';
  }

  private static function render_reports_tab(string $default_date,?array $selected_report,array $saved_reports): void {
    echo '<h2>Pull Report Data</h2><p>Generate a saved draft report, review it, and email it when it looks right.</p><form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';
    wp_nonce_field('roxy_grosses_pull_report'); echo '<input type="hidden" name="action" value="roxy_grosses_pull_report"><table class="form-table"><tbody><tr><th scope="row"><label for="roxy-grosses-report-date">Report end date</label></th><td><input id="roxy-grosses-report-date" type="date" name="report_date" value="'.esc_attr($default_date).'"></td></tr></tbody></table>'; submit_button('Pull And Save Draft','primary'); echo '</form>';
    if($selected_report){ $summary=is_array($selected_report['summary']??null)?$selected_report['summary']:[]; echo '<hr><h2>Review Saved Report #'.esc_html((string) $selected_report['id']).'</h2><p>Created: '.esc_html((string) $selected_report['created_at']).' | Status: '.esc_html((string) $selected_report['status']).'</p><table class="widefat striped" style="max-width:980px"><thead><tr><th>Report Date</th><th>Show Time</th><th>Theater</th><th>Film Title</th><th>General</th><th>Discount</th><th>Group</th><th>Total Tickets</th><th>Gross</th></tr></thead><tbody>';
      foreach((array) ($selected_report['rows']??[]) as $row){ echo '<tr><td>'.esc_html((string) ($row['report_date']??'')).'</td><td>'.esc_html((string) ($row['show_time']??'')).'</td><td>'.esc_html((string) ($row['theater_name']??'')).'</td><td>'.esc_html((string) ($row['film_title']??'')).'</td><td>'.esc_html(number_format_i18n((int) ($row['general_qty']??0))).'</td><td>'.esc_html(number_format_i18n((int) ($row['discount_qty']??0))).'</td><td>'.esc_html(number_format_i18n((int) ($row['group_qty']??0))).'</td><td>'.esc_html(number_format_i18n((int) ($row['total_tickets']??0))).'</td><td>$'.esc_html(number_format((float) ($row['gross_total']??0),2)).'</td></tr>'; }
      echo '</tbody></table><p style="margin-top:12px;"><strong>Total Gross:</strong> $'.esc_html(number_format((float) ($summary['gross_total']??0),2)).' | <strong>Total Tickets:</strong> '.esc_html(number_format_i18n((int) ($summary['total_tickets']??0))).'</p>';
      if(($selected_report['status']??'')!=='emailed'){ echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'" style="margin-top:12px;">'; wp_nonce_field('roxy_grosses_send_saved_report'); echo '<input type="hidden" name="action" value="roxy_grosses_send_saved_report"><input type="hidden" name="report_id" value="'.esc_attr((string) $selected_report['id']).'">'; submit_button('Email This Saved Report','secondary','submit',false); echo '</form>'; }
    }
    echo '<hr><h2>Saved Reports</h2><table class="widefat striped" style="max-width:980px"><thead><tr><th>ID</th><th>End Date</th><th>Status</th><th>Rows</th><th>Tickets</th><th>Gross</th><th>Created</th><th>Action</th></tr></thead><tbody>';
    foreach($saved_reports as $report_row){ $view_url=add_query_arg(['page'=>'roxy-grosses','tab'=>'daily','report_id'=>(int) $report_row['id']],admin_url('admin.php')); echo '<tr><td>'.esc_html((string) $report_row['id']).'</td><td>'.esc_html((string) $report_row['report_end_date']).'</td><td>'.esc_html((string) $report_row['status']).'</td><td>'.esc_html(number_format_i18n((int) $report_row['row_count'])).'</td><td>'.esc_html(number_format_i18n((int) $report_row['summary_tickets'])).'</td><td>$'.esc_html(number_format((float) $report_row['summary_gross'],2)).'</td><td>'.esc_html((string) $report_row['created_at']).'</td><td><a class="button button-small" href="'.esc_url($view_url).'">Review</a></td></tr>'; }
    if(!$saved_reports) echo '<tr><td colspan="8">No saved reports yet.</td></tr>'; echo '</tbody></table>';
  }

  public static function render_dashboard_panel(string $page_slug = 'roxy-suite', ?string $page_tab = null): void {
    $timezone = new \DateTimeZone(self::get_report_timezone());
    $default_date = wp_date('Y-m-d', null, $timezone);
    $scope = isset($_GET['grosses_dashboard_scope']) ? sanitize_key((string) wp_unslash($_GET['grosses_dashboard_scope'])) : 'last12';
    if (!in_array($scope, ['all', 'last12', 'year'], true)) {
      $scope = 'last12';
    }
    $dashboard_year = isset($_GET['grosses_dashboard_year']) ? max(0, (int) $_GET['grosses_dashboard_year']) : (int) wp_date('Y');
    $snapshot = Store::dashboard_snapshot($scope, $dashboard_year);
    $year_options = Store::distinct_years();
    echo '<div style="margin:16px 0 24px; padding:16px; background:#fff; border:1px solid #dcdcde; border-radius:4px;">';
    echo '<h2 style="margin-top:0;">Grosses Dashboard</h2>';
    echo '<form method="get" action="'.esc_url(admin_url('admin.php')).'" style="display:flex; gap:12px; align-items:end; flex-wrap:wrap; margin-bottom:16px;">';
    echo '<input type="hidden" name="page" value="'.esc_attr($page_slug).'">';
    if ($page_tab !== null && $page_tab !== '') {
      echo '<input type="hidden" name="tab" value="'.esc_attr($page_tab).'">';
    }
    echo '<div><label><strong>Dashboard Window</strong></label><br><select name="grosses_dashboard_scope">';
    foreach (['all' => 'All time', 'last12' => 'Last 12 months', 'year' => 'Specific year'] as $value => $label) {
      echo '<option value="'.esc_attr($value).'" '.selected($scope, $value, false).'>'.esc_html($label).'</option>';
    }
    echo '</select></div>';
    echo '<div><label><strong>Year</strong></label><br><select name="grosses_dashboard_year"><option value="0">Select year</option>';
    foreach ($year_options as $year_option) {
      echo '<option value="'.esc_attr((string) $year_option).'" '.selected($dashboard_year, (int) $year_option, false).'>'.esc_html((string) $year_option).'</option>';
    }
    echo '</select></div>';
    submit_button('Apply Dashboard Range', 'secondary', '', false);
    echo '</form>';
    echo '<div style="display:flex; gap:16px; flex-wrap:wrap; align-items:flex-start; justify-content:space-between;">';
    echo '<div style="display:flex; gap:16px; flex-wrap:wrap;">';
    foreach ([
      ['Movies Ticket Gross', '$' . number_format((float) ($snapshot['movies']['gross'] ?? 0), 2)],
      ['Movies Concessions', '$' . number_format((float) ($snapshot['movies']['concessions'] ?? 0), 2)],
      ['Live Ticket Gross', '$' . number_format((float) ($snapshot['live']['gross'] ?? 0), 2)],
      ['Live Concessions', '$' . number_format((float) ($snapshot['live']['concessions'] ?? 0), 2)],
      ['Rentals Invoice', '$' . number_format((float) ($snapshot['rentals']['invoice_total'] ?? 0), 2)],
      ['Rentals Concessions', '$' . number_format((float) ($snapshot['rentals']['concessions'] ?? 0), 2)],
      ['Legacy Ticket Gross', '$' . number_format((float) ($snapshot['legacy']['gross'] ?? 0), 2)],
      ['Legacy Concessions', '$' . number_format((float) ($snapshot['legacy']['concessions'] ?? 0), 2)],
    ] as $card) {
      echo '<div style="min-width:170px;"><div style="font-size:12px; color:#50575e; text-transform:uppercase;">'.esc_html($card[0]).'</div><div style="font-size:22px; font-weight:600; margin-top:6px;">'.esc_html($card[1]).'</div></div>';
    }
    foreach ([
      ['Avg Ticket Gross / Studio', (array) ($snapshot['studio_avg_gross'] ?? [])],
      ['Avg Concessions / Studio', (array) ($snapshot['studio_avg_concessions'] ?? [])],
      ['Avg Ticket Gross / Genre', (array) ($snapshot['genre_avg_gross'] ?? [])],
      ['Avg Concessions / Genre', (array) ($snapshot['genre_avg_concessions'] ?? [])],
    ] as $card) {
      $label = (string) ($card[1]['label'] ?? '');
      $average = '$' . number_format((float) ($card[1]['average_value'] ?? 0), 2);
      $note = $label !== '' ? $label . ' • ' . number_format_i18n((int) ($card[1]['row_count'] ?? 0)) . ' rows' : 'No movie metadata yet';
      echo '<div style="min-width:170px;"><div style="font-size:12px; color:#50575e; text-transform:uppercase;">'.esc_html($card[0]).'</div><div style="font-size:22px; font-weight:600; margin-top:6px;">'.esc_html($average).'</div><div style="margin-top:4px; color:#646970; font-size:12px;">'.esc_html($note).'</div></div>';
    }
    echo '</div><div style="min-width:260px;">';
    echo '<p style="margin-top:0;"><strong>Last Sync:</strong> '.esc_html((string) ($snapshot['last_sync']['created_at'] ?? 'Never')).'</p>';
    echo '<p><strong>Last Grosses Email:</strong> '.esc_html((string) ($snapshot['last_grosses_email']['created_at'] ?? 'Never')).'</p>';
    echo '<p><strong>Last Advertiser Email:</strong> '.esc_html((string) ($snapshot['last_advertiser_email']['created_at'] ?? 'Never')).'</p>';
    echo '<p><strong>Recent Anomalies (30 days):</strong> '.esc_html(number_format_i18n((int) ($snapshot['recent_anomalies'] ?? 0))).'</p>';
    echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'" style="margin-top:12px;">';
    wp_nonce_field('roxy_grosses_run_now');
    echo '<input type="hidden" name="action" value="roxy_grosses_run_now">';
    echo '<label for="roxy-grosses-run-now-date"><strong>Run automation now</strong></label><br><input id="roxy-grosses-run-now-date" type="date" name="report_date" value="'.esc_attr($default_date).'"> ';
    submit_button('Run Now','secondary','submit',false);
    echo '</form></div></div>';
    echo '<div style="margin-top:20px;"><h2 style="margin:0 0 12px;">Top Performers</h2><p style="margin-top:0;">Based on: '.esc_html((string) ($snapshot['top_window_label'] ?? '')).'.</p><div style="display:flex; gap:16px; flex-wrap:wrap;">';
    self::render_top_performer_list('Movies By Ticket Gross', (array) ($snapshot['top_movies_by_gross'] ?? []), 'movie_title', 'Ticket Gross', 'gross', 'Concessions', 'concessions');
    self::render_top_performer_list('Movies By Concessions', (array) ($snapshot['top_movies_by_concessions'] ?? []), 'movie_title', 'Concessions', 'concessions', 'Ticket Gross', 'gross');
    self::render_top_performer_list('Studios By Ticket Gross', (array) ($snapshot['top_studios_by_gross'] ?? []), 'label', 'Ticket Gross', 'primary_total', 'Concessions', 'secondary_total');
    self::render_top_performer_list('Studios By Concessions', (array) ($snapshot['top_studios_by_concessions'] ?? []), 'label', 'Concessions', 'primary_total', 'Ticket Gross', 'secondary_total');
    self::render_top_performer_list('Genres By Ticket Gross', (array) ($snapshot['top_genres_by_gross'] ?? []), 'label', 'Ticket Gross', 'primary_total', 'Concessions', 'secondary_total');
    self::render_top_performer_list('Genres By Concessions', (array) ($snapshot['top_genres_by_concessions'] ?? []), 'label', 'Concessions', 'primary_total', 'Ticket Gross', 'secondary_total');
    self::render_top_performer_list('Live Shows By Ticket Gross', (array) ($snapshot['top_live_by_gross'] ?? []), 'show_title', 'Ticket Gross', 'gross', 'Concessions', 'concessions');
    self::render_top_performer_list('Rentals By Concessions', (array) ($snapshot['top_rentals_by_concessions'] ?? []), 'rental_title', 'Concessions', 'concessions', 'Invoice', 'invoice_total');
    echo '</div></div></div>';
  }

  private static function render_top_performer_list(string $title, array $rows, string $label_key, string $primary_label, string $primary_key, string $secondary_label, string $secondary_key): void {
    echo '<div style="min-width:260px; flex:1; padding:14px; background:#fff; border:1px solid #dcdcde; border-radius:4px;"><h3 style="margin-top:0;">'.esc_html($title).'</h3><ol style="margin:0; padding-left:20px;">';
    foreach ($rows as $row) {
      $label = (string) ($row[$label_key] ?? '');
      $primary = '$' . number_format((float) ($row[$primary_key] ?? 0), 2);
      $secondary = '$' . number_format((float) ($row[$secondary_key] ?? 0), 2);
      echo '<li style="margin-bottom:8px;"><strong>'.esc_html($label !== '' ? $label : 'Untitled').'</strong><br><span style="color:#50575e;">'.esc_html($primary_label).' '.esc_html($primary).' | '.esc_html($secondary_label).' '.esc_html($secondary).'</span></li>';
    }
    if (!$rows) {
      echo '<li>No rows yet.</li>';
    }
    echo '</ol></div>';
  }

  private static function render_filter_presets(string $dataset, array $base_query): void {
    $timezone = new \DateTimeZone(self::get_report_timezone());
    $today = new \DateTimeImmutable('now', $timezone);
    $presets = [
      'This Month' => [$today->format('Y-m-01'), $today->format('Y-m-d')],
      'Last Month' => [$today->modify('first day of last month')->format('Y-m-d'), $today->modify('last day of last month')->format('Y-m-d')],
      'Current Year' => [$today->format('Y-01-01'), $today->format('Y-m-d')],
      'Last 12 Months' => [$today->modify('first day of -11 months')->format('Y-m-d'), $today->format('Y-m-d')],
    ];
    echo '<div style="display:flex; gap:8px; flex-wrap:wrap; margin:-4px 0 16px;">';
    foreach ($presets as $label => [$from, $to]) {
      $query = $base_query;
      switch ($dataset) {
        case 'live':
          $query['live_from'] = $from;
          $query['live_to'] = $to;
          $query['live_year'] = null;
          $query['live_month'] = '';
          $query['live_day'] = '';
          break;
        case 'rentals':
          $query['rental_from'] = $from;
          $query['rental_to'] = $to;
          $query['rental_year'] = null;
          $query['rental_month'] = '';
          $query['rental_day'] = '';
          break;
        case 'legacy':
          $query['legacy_from'] = $from;
          $query['legacy_to'] = $to;
          $query['legacy_year'] = null;
          $query['legacy_month'] = '';
          $query['legacy_day'] = '';
          break;
        default:
          $query['history_from'] = $from;
          $query['history_to'] = $to;
          $query['history_year'] = null;
          $query['history_month'] = '';
          $query['history_day'] = '';
          break;
      }
      $query = array_filter(array_merge(['page' => 'roxy-grosses'], $query), static fn($value) => $value !== null && $value !== '');
      echo '<a class="button button-secondary" href="'.esc_url(add_query_arg($query, admin_url('admin.php'))).'">'.esc_html($label).'</a>';
    }
    echo '</div>';
  }

  private static function render_export_form(string $dataset, array $hidden_fields): void {
    echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'" style="margin:-4px 0 16px;">';
    wp_nonce_field('roxy_grosses_export_csv');
    echo '<input type="hidden" name="action" value="roxy_grosses_export_csv"><input type="hidden" name="dataset" value="'.esc_attr($dataset).'">';
    foreach ($hidden_fields as $key => $value) {
      if ($value === null || $value === '' || $value === 0) {
        continue;
      }
      echo '<input type="hidden" name="'.esc_attr((string) $key).'" value="'.esc_attr((string) $value).'">';
    }
    submit_button('Export CSV', 'secondary', 'submit', false);
    echo '</form>';
  }

  private static function editing_row_id(string $dataset): int {
    $requested_dataset = isset($_GET['edit_dataset']) ? sanitize_key((string) wp_unslash($_GET['edit_dataset'])) : '';
    if ($requested_dataset !== $dataset) {
      return 0;
    }
    return isset($_GET['edit_id']) ? max(0, (int) $_GET['edit_id']) : 0;
  }

  private static function edit_url(string $dataset, int $row_id, array $query): string {
    $args = array_filter(array_merge([
      'page' => 'roxy-grosses',
      'edit_dataset' => $dataset,
      'edit_id' => $row_id,
    ], $query), static fn($value) => $value !== null && $value !== '');
    return add_query_arg($args, admin_url('admin.php'));
  }

  private static function render_movie_edit_row(array $row, string $search, int $year, string $month, string $day, string $date_from = '', string $date_to = ''): void {
    echo '<tr><td colspan="13"><form method="post" action="'.esc_url(admin_url('admin-post.php')).'" style="display:flex; gap:8px; flex-wrap:wrap; align-items:end;">';
    wp_nonce_field('roxy_grosses_update_row');
    echo '<input type="hidden" name="action" value="roxy_grosses_update_row"><input type="hidden" name="dataset" value="movies"><input type="hidden" name="entry_id" value="'.esc_attr((string) ($row['id'] ?? 0)).'">';
    echo '<input type="hidden" name="search" value="'.esc_attr($search).'"><input type="hidden" name="history_from" value="'.esc_attr($date_from).'"><input type="hidden" name="history_to" value="'.esc_attr($date_to).'"><input type="hidden" name="history_year" value="'.esc_attr((string) $year).'"><input type="hidden" name="history_month" value="'.esc_attr($month).'"><input type="hidden" name="history_day" value="'.esc_attr($day).'">';
    self::text_input('report_date', 'Date', (string) ($row['report_date'] ?? ''), 'date');
    self::text_input('movie_title', 'Movie', (string) ($row['movie_title'] ?? ''));
    self::text_input('studio', 'Studio', (string) ($row['studio'] ?? ''));
    self::text_input('genre', 'Genre', (string) ($row['genre'] ?? ''));
    self::text_input('show_time', 'Show Time', (string) ($row['show_time'] ?? ''));
    self::number_input('general_qty', 'General', (int) ($row['general_qty'] ?? 0));
    self::number_input('discount_qty', 'Discount', (int) ($row['discount_qty'] ?? 0));
    self::number_input('group_qty', 'Group', (int) ($row['group_qty'] ?? 0));
    self::number_input('live_qty', 'Free', (int) ($row['live_qty'] ?? 0));
    self::number_input('total_tickets', 'Total', (int) ($row['total_tickets'] ?? 0));
    self::text_input('gross_total', 'Ticket Gross', (string) ($row['gross_total'] ?? '0.00'), 'number', '0.01');
    self::text_input('concessions_total', 'Concessions', (string) ($row['concessions_total'] ?? '0.00'), 'number', '0.01');
    submit_button('Save Row', 'primary', 'submit', false);
    echo '</form></td></tr>';
  }

  private static function render_live_edit_row(array $row, string $search, int $year, string $month, string $day, string $date_from = '', string $date_to = ''): void {
    echo '<tr><td colspan="11"><form method="post" action="'.esc_url(admin_url('admin-post.php')).'" style="display:flex; gap:8px; flex-wrap:wrap; align-items:end;">';
    wp_nonce_field('roxy_grosses_update_row');
    echo '<input type="hidden" name="action" value="roxy_grosses_update_row"><input type="hidden" name="dataset" value="live"><input type="hidden" name="entry_id" value="'.esc_attr((string) ($row['id'] ?? 0)).'">';
    echo '<input type="hidden" name="live_search" value="'.esc_attr($search).'"><input type="hidden" name="live_from" value="'.esc_attr($date_from).'"><input type="hidden" name="live_to" value="'.esc_attr($date_to).'"><input type="hidden" name="live_year" value="'.esc_attr((string) $year).'"><input type="hidden" name="live_month" value="'.esc_attr($month).'"><input type="hidden" name="live_day" value="'.esc_attr($day).'">';
    self::text_input('report_date', 'Date', (string) ($row['report_date'] ?? ''), 'date');
    self::text_input('show_title', 'Show', (string) ($row['show_title'] ?? ''));
    self::text_input('show_time', 'Show Time', (string) ($row['show_time'] ?? ''));
    self::number_input('presale_qty', 'Presale', (int) ($row['presale_qty'] ?? 0));
    self::number_input('online_qty', 'Online', (int) ($row['online_qty'] ?? 0));
    self::number_input('door_qty', 'Door', (int) ($row['door_qty'] ?? 0));
    self::number_input('group_sub_qty', 'Group/Sub', (int) ($row['group_sub_qty'] ?? 0));
    self::number_input('total_tickets', 'Total', (int) ($row['total_tickets'] ?? 0));
    self::text_input('gross_total', 'Ticket Gross', (string) ($row['gross_total'] ?? '0.00'), 'number', '0.01');
    self::text_input('concessions_total', 'Concessions', (string) ($row['concessions_total'] ?? '0.00'), 'number', '0.01');
    submit_button('Save Row', 'primary', 'submit', false);
    echo '</form></td></tr>';
  }

  private static function render_rental_edit_row(array $row, string $search, int $year, string $month, string $day, string $date_from = '', string $date_to = ''): void {
    echo '<tr><td colspan="9"><form method="post" action="'.esc_url(admin_url('admin-post.php')).'" style="display:flex; gap:8px; flex-wrap:wrap; align-items:end;">';
    wp_nonce_field('roxy_grosses_update_row');
    echo '<input type="hidden" name="action" value="roxy_grosses_update_row"><input type="hidden" name="dataset" value="rentals"><input type="hidden" name="entry_id" value="'.esc_attr((string) ($row['id'] ?? 0)).'">';
    echo '<input type="hidden" name="rental_search" value="'.esc_attr($search).'"><input type="hidden" name="rental_from" value="'.esc_attr($date_from).'"><input type="hidden" name="rental_to" value="'.esc_attr($date_to).'"><input type="hidden" name="rental_year" value="'.esc_attr((string) $year).'"><input type="hidden" name="rental_month" value="'.esc_attr($month).'"><input type="hidden" name="rental_day" value="'.esc_attr($day).'">';
    self::text_input('report_date', 'Date', (string) ($row['report_date'] ?? ''), 'date');
    self::text_input('rental_title', 'Rental', (string) ($row['rental_title'] ?? ''));
    self::text_input('rental_type', 'Type', (string) ($row['rental_type'] ?? ''));
    self::text_input('customer_name', 'Customer', (string) ($row['customer_name'] ?? ''));
    self::text_input('status', 'Status', (string) ($row['status'] ?? ''));
    self::text_input('show_time', 'Show Time', (string) ($row['show_time'] ?? ''));
    self::text_input('invoice_amount', 'Invoice', (string) ($row['invoice_amount'] ?? '0.00'), 'number', '0.01');
    self::text_input('concessions_total', 'Concessions', (string) ($row['concessions_total'] ?? '0.00'), 'number', '0.01');
    self::text_input('notes', 'Notes', (string) ($row['notes'] ?? ''));
    submit_button('Save Row', 'primary', 'submit', false);
    echo '</form></td></tr>';
  }

  private static function render_legacy_edit_row(array $row, string $search, int $year, string $month, string $day, string $date_from = '', string $date_to = ''): void {
    echo '<tr><td colspan="12"><form method="post" action="'.esc_url(admin_url('admin-post.php')).'" style="display:flex; gap:8px; flex-wrap:wrap; align-items:end;">';
    wp_nonce_field('roxy_grosses_update_row');
    echo '<input type="hidden" name="action" value="roxy_grosses_update_row"><input type="hidden" name="dataset" value="legacy"><input type="hidden" name="entry_id" value="'.esc_attr((string) ($row['id'] ?? 0)).'">';
    echo '<input type="hidden" name="legacy_search" value="'.esc_attr($search).'"><input type="hidden" name="legacy_from" value="'.esc_attr($date_from).'"><input type="hidden" name="legacy_to" value="'.esc_attr($date_to).'"><input type="hidden" name="legacy_year" value="'.esc_attr((string) $year).'"><input type="hidden" name="legacy_month" value="'.esc_attr($month).'"><input type="hidden" name="legacy_day" value="'.esc_attr($day).'">';
    self::text_input('week_start_date', 'Week Of', (string) ($row['week_start_date'] ?? ''), 'date');
    self::text_input('week_end_date', 'Week End', (string) ($row['week_end_date'] ?? ''), 'date');
    self::text_input('movie_title', 'Movie', (string) ($row['movie_title'] ?? ''));
    self::text_input('rating', 'Rating', (string) ($row['rating'] ?? ''));
    self::text_input('weeks_run', 'Weeks', (string) ($row['weeks_run'] ?? ''));
    self::number_input('general_qty', 'General', (int) ($row['general_qty'] ?? 0));
    self::number_input('discount_qty', 'Discount', (int) ($row['discount_qty'] ?? 0));
    self::number_input('free_qty', 'Free', (int) ($row['free_qty'] ?? 0));
    self::number_input('total_attendance', 'Total', (int) ($row['total_attendance'] ?? 0));
    self::text_input('gross_total', 'Ticket Gross', (string) ($row['gross_total'] ?? '0.00'), 'number', '0.01');
    self::text_input('concessions_total', 'Concessions', (string) ($row['concessions_total'] ?? '0.00'), 'number', '0.01');
    submit_button('Save Row', 'primary', 'submit', false);
    echo '</form></td></tr>';
  }

  private static function text_input(string $name, string $label, string $value, string $type = 'text', string $step = ''): void {
    echo '<div><label><strong>'.esc_html($label).'</strong></label><br><input type="'.esc_attr($type).'" name="'.esc_attr($name).'" value="'.esc_attr($value).'"'.($step !== '' ? ' step="'.esc_attr($step).'"' : '').'></div>';
  }

  private static function number_input(string $name, string $label, int $value): void {
    echo '<div><label><strong>'.esc_html($label).'</strong></label><br><input type="number" min="0" step="1" name="'.esc_attr($name).'" value="'.esc_attr((string) $value).'"></div>';
  }

  public static function sanitize_line_list(string $value): string {
    $lines=preg_split('/[\r\n,]+/',$value); $lines=array_filter(array_map(static fn($line): string=>sanitize_text_field(trim((string) $line)),(array) $lines));
    return implode("\n",array_values(array_unique($lines)));
  }
  public static function line_list(string $value): array {
    $lines=preg_split('/[\r\n,]+/',$value);
    return array_values(array_filter(array_map(static fn($line): string=>trim((string) $line),(array) $lines)));
  }
  public static function sanitize_email_list(string $value): string {
    $emails=preg_split('/[\r\n,;]+/',$value); $emails=array_filter(array_map(static fn($email): string=>sanitize_email(trim((string) $email)),(array) $emails));
    return implode(',',array_values(array_unique($emails)));
  }
  public static function email_list(): array { return array_values(array_filter(array_map('sanitize_email',array_map('trim',explode(',',(string) self::get('recipient_emails','')))))); }
  public static function advertiser_email_list(): array { return array_values(array_filter(array_map('sanitize_email',array_map('trim',explode(',',(string) self::get('advertiser_emails','')))))); }
  public static function live_email_list(): array { return array_values(array_filter(array_map('sanitize_email',array_map('trim',explode(',',(string) self::get('live_email_recipients','')))))); }
  public static function admin_email(): string { return sanitize_email((string) self::get('admin_email',get_option('admin_email'))); }
  public static function square_access_token(): string { return trim((string) self::get('square_access_token','')); }
  public static function has_square_access_token(): bool { return self::square_access_token() !== ''; }
  public static function sanitize_mappings(string $value): string {
    $clean=[]; foreach((array) preg_split('/\r\n|\r|\n/',$value) as $line){ $parts=array_map('trim',explode('|',(string) $line)); if(count($parts)<2||$parts[0]===''||$parts[1]==='') continue; $clean[]=sanitize_text_field($parts[0]).'|'.sanitize_text_field($parts[1]).'|'.sanitize_text_field($parts[2]??''); }
    return implode("\n",$clean);
  }
  public static function mappings(): array {
    $mappings=[]; foreach((array) preg_split('/\r\n|\r|\n/',(string) self::get('film_mappings','')) as $line){ $parts=array_map('trim',explode('|',(string) $line)); if(count($parts)<2||$parts[0]===''||$parts[1]==='') continue; $mappings[]=['match'=>mb_strtolower($parts[0]),'title'=>$parts[1],'code'=>$parts[2]??'']; }
    return $mappings;
  }
  public static function sanitize_studio_mappings(string $value): string {
    $clean=[]; foreach((array) preg_split('/\r\n|\r|\n/',$value) as $line){ $parts=array_map('trim',explode('|',(string) $line)); if(count($parts)<2||$parts[0]===''||$parts[1]==='') continue; $clean[]=sanitize_text_field($parts[0]).'|'.sanitize_text_field($parts[1]); }
    return implode("\n",$clean);
  }
  public static function studio_mappings(): array {
    $mappings=[]; foreach((array) preg_split('/\r\n|\r|\n/',(string) self::get('studio_mappings','')) as $line){ $parts=array_map('trim',explode('|',(string) $line)); if(count($parts)<2||$parts[0]===''||$parts[1]==='') continue; $mappings[]=['match'=>mb_strtolower($parts[0]),'studio'=>$parts[1]]; }
    return $mappings;
  }
  public static function sanitize_time(string $time): string { return preg_match('/^\d{2}:\d{2}$/',$time)?$time:'20:00'; }
  public static function sanitize_timezone(string $timezone): string {
    $timezone=sanitize_text_field($timezone); try { new \DateTimeZone($timezone); return $timezone; } catch (\Exception $e) { return wp_timezone_string()?:'America/Los_Angeles'; }
  }
  private static function encryption_key(): string {
    return hash('sha256', (defined('AUTH_SALT') ? AUTH_SALT : 'roxy-grosses') . '|' . (defined('SECURE_AUTH_SALT') ? SECURE_AUTH_SALT : 'roxy-grosses'), true);
  }

  private static function encrypt_secret(string $plain): string {
    $plain = trim($plain);
    if ($plain === '') {
      return '';
    }
    $iv = random_bytes(12);
    $tag = '';
    $cipher = openssl_encrypt($plain, 'aes-256-gcm', self::encryption_key(), OPENSSL_RAW_DATA, $iv, $tag);
    if ($cipher === false || $tag === '') {
      return $plain;
    }
    return self::ENCRYPTION_PREFIX . base64_encode($iv . $tag . $cipher);
  }

  private static function decrypt_secret(string $value): string {
    $value = trim($value);
    if ($value === '') {
      return '';
    }
    if (!str_starts_with($value, self::ENCRYPTION_PREFIX)) {
      return $value;
    }
    $raw = base64_decode(substr($value, strlen(self::ENCRYPTION_PREFIX)), true);
    if ($raw === false || strlen($raw) < 29) {
      return '';
    }
    $iv = substr($raw, 0, 12);
    $tag = substr($raw, 12, 16);
    $cipher = substr($raw, 28);
    $plain = openssl_decrypt($cipher, 'aes-256-gcm', self::encryption_key(), OPENSSL_RAW_DATA, $iv, $tag);
    return $plain === false ? '' : $plain;
  }
}



