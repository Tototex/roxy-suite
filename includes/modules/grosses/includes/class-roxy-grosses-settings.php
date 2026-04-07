<?php
namespace RoxyGrosses;
if (!defined('ABSPATH')) exit;

class Settings {
  public const OPTION_KEY='roxy_grosses_settings';
  public const STATUS_KEY='roxy_grosses_last_report';

  public static function init(): void {
    add_action('admin_init',[__CLASS__,'register_settings']);
  }
  public static function current_tab(): string {
    $tab=isset($_GET['tab'])?sanitize_key((string) wp_unslash($_GET['tab'])):'database';
    return in_array($tab,['database','legacy-weekly','settings','logs'],true)?$tab:'database';
  }
  public static function defaults(): array {
    return [
      'square_environment'=>'production','square_access_token'=>'','square_location_ids'=>'',
      'report_timezone'=>wp_timezone_string()?:'America/Los_Angeles',
      'ticket_keywords'=>"ticket\nadmission",'exclude_keywords'=>"popcorn\nsoda\ndrink\ncandy\nmembership",
      'film_mappings'=>'','studio_mappings'=>'',
      'recipient_emails'=>'comscore@example.com,lori@example.com','advertiser_emails'=>'','admin_email'=>get_option('admin_email'),
      'email_subject'=>'Roxy grosses for {report_date}',
      'email_body'=>"Attached is the grosses report for {report_date}.\n\nGenerated automatically by the Roxy Grosses plugin.",
      'advertiser_email_subject'=>'Roxy advertiser summary for {month_name} {year}',
      'advertiser_email_body'=>"Attached is the advertiser summary workbook for {month_name} {year}.",
      'theater_name'=>'Newport Roxy Theater','general_price'=>'12','discount_price'=>'8','group_price'=>'5','lookback_days'=>'0',
      'workbook_template_path'=>'I:\\My Drive\\Grosses\\Roxy_Box_Office_{year}.xlsx',
      'schedule_enabled'=>'1','schedule_days'=>['fri','sat','sun'],'schedule_time'=>'20:00',
      'advertiser_schedule_enabled'=>'1','advertiser_schedule_day'=>'1','advertiser_schedule_time'=>'09:00',
    ];
  }
  public static function ensure_defaults(): void {
    if (get_option(self::OPTION_KEY,null)===null) add_option(self::OPTION_KEY,self::defaults());
  }
  public static function get_all(): array {
    $saved=get_option(self::OPTION_KEY,[]); if(!is_array($saved)) $saved=[];
    $all=wp_parse_args($saved,self::defaults()); $all['schedule_days']=self::sanitize_days($all['schedule_days']??[]);
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
    add_settings_section('roxy_grosses_schedule','Schedule',fn()=>print('<p>Pick the automatic report days and send times. Use the manual forms for odd schedules.</p>'),'roxy-grosses');
    $fields=[
      'square_environment'=>['Square environment','roxy_grosses_square'],'square_access_token'=>['Square access token','roxy_grosses_square'],
      'square_location_ids'=>['Square location IDs','roxy_grosses_square'],'report_timezone'=>['Report timezone','roxy_grosses_square'],
      'ticket_keywords'=>['Ticket keywords','roxy_grosses_square'],'exclude_keywords'=>['Exclude keywords','roxy_grosses_square'],
      'film_mappings'=>['Film mappings','roxy_grosses_square'],'recipient_emails'=>['Recipient emails','roxy_grosses_email'],
      'admin_email'=>['Admin alert email','roxy_grosses_email'],'email_subject'=>['Email subject','roxy_grosses_email'],
      'email_body'=>['Email body','roxy_grosses_email'],'theater_name'=>['Theater name','roxy_grosses_email'],
      'general_price'=>['General ticket price','roxy_grosses_email'],'discount_price'=>['Discount ticket price','roxy_grosses_email'],
      'group_price'=>['Group ticket price','roxy_grosses_email'],'lookback_days'=>['Previous days to include','roxy_grosses_email'],
      'advertiser_emails'=>['Advertiser emails','roxy_grosses_email'],'advertiser_email_subject'=>['Advertiser email subject','roxy_grosses_email'],
      'advertiser_email_body'=>['Advertiser email body','roxy_grosses_email'],'schedule_enabled'=>['Enable automatic grosses sends','roxy_grosses_schedule'],
      'schedule_days'=>['Automatic grosses days','roxy_grosses_schedule'],'schedule_time'=>['Automatic grosses send time','roxy_grosses_schedule'],
      'advertiser_schedule_enabled'=>['Enable monthly advertiser email','roxy_grosses_schedule'],'advertiser_schedule_day'=>['Monthly advertiser send day','roxy_grosses_schedule'],'advertiser_schedule_time'=>['Monthly advertiser send time','roxy_grosses_schedule'],
    ];
    foreach($fields as $key=>$field){ add_settings_field($key,$field[0],[__CLASS__,'render_field'],'roxy-grosses',$field[1],['key'=>$key]); }
  }

  public static function sanitize($input): array {
    $d=self::defaults(); $input=is_array($input)?$input:[];
    $sanitized=[
      'square_environment'=>in_array(($input['square_environment']??''),['production','sandbox'],true)?$input['square_environment']:$d['square_environment'],
      'square_access_token'=>sanitize_text_field((string) ($input['square_access_token']??$d['square_access_token'])),
      'square_location_ids'=>self::sanitize_line_list((string) ($input['square_location_ids']??$d['square_location_ids'])),
      'report_timezone'=>self::sanitize_timezone((string) ($input['report_timezone']??$d['report_timezone'])),
      'ticket_keywords'=>self::sanitize_line_list((string) ($input['ticket_keywords']??$d['ticket_keywords'])),
      'exclude_keywords'=>self::sanitize_line_list((string) ($input['exclude_keywords']??$d['exclude_keywords'])),
      'film_mappings'=>self::sanitize_mappings((string) ($input['film_mappings']??$d['film_mappings'])),
      'studio_mappings'=>self::sanitize_studio_mappings((string) ($input['studio_mappings']??$d['studio_mappings'])),
      'recipient_emails'=>self::sanitize_email_list((string) ($input['recipient_emails']??$d['recipient_emails'])),
      'advertiser_emails'=>self::sanitize_email_list((string) ($input['advertiser_emails']??$d['advertiser_emails'])),
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
      'schedule_enabled'=>!empty($input['schedule_enabled'])?'1':'0','schedule_days'=>self::sanitize_days($input['schedule_days']??$d['schedule_days']),
      'schedule_time'=>self::sanitize_time((string) ($input['schedule_time']??$d['schedule_time'])),
      'advertiser_schedule_enabled'=>!empty($input['advertiser_schedule_enabled'])?'1':'0',
      'advertiser_schedule_day'=>(string) min(31,max(1,(int) ($input['advertiser_schedule_day']??$d['advertiser_schedule_day']))),
      'advertiser_schedule_time'=>self::sanitize_time((string) ($input['advertiser_schedule_time']??$d['advertiser_schedule_time'])),
    ];
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
        echo '<input type="password" class="regular-text code" name="'.esc_attr($name).'" value="'.esc_attr((string) $value).'" autocomplete="off"><p class="description">Personal access token or OAuth token with Orders read access.</p>'; return;
      case 'square_location_ids': case 'ticket_keywords': case 'exclude_keywords': case 'film_mappings': case 'email_body': case 'advertiser_email_body':
        $rows=$key==='film_mappings'?'8':'5';
        echo '<textarea class="large-text code" rows="'.esc_attr($rows).'" name="'.esc_attr($name).'">'.esc_textarea((string) $value).'</textarea>';
        if($key==='square_location_ids') echo '<p class="description">One Square location ID per line. Square requires at least one location ID for order searches.</p>';
        elseif($key==='ticket_keywords') echo '<p class="description">One keyword per line. A line item must match at least one keyword to count as a ticket.</p>';
        elseif($key==='exclude_keywords') echo '<p class="description">One keyword per line. Matching line items are always ignored.</p>';
        elseif($key==='film_mappings') echo '<p class="description">One mapping per line in the format: match text|Comscore title|Film code.</p>';
        elseif($key==='email_body') echo '<p class="description">You can use {report_date}, {theater_name}, {gross_total}, and {ticket_total}.</p>';
          else echo '<p class="description">You can use {theater_name}, {month_name}, {month}, {year}, {attendance_total}, {gross_total}, {period_label}, {start_month}, and {end_month}.</p>';
        return;
      case 'recipient_emails': case 'advertiser_emails': case 'admin_email': case 'email_subject': case 'advertiser_email_subject': case 'theater_name': case 'report_timezone': case 'lookback_days': case 'advertiser_schedule_day':
        $class=in_array($key,['recipient_emails','advertiser_emails'],true)?'regular-text code':'regular-text';
        echo '<input type="text" class="'.esc_attr($class).'" name="'.esc_attr($name).'" value="'.esc_attr((string) $value).'">';
        if($key==='recipient_emails'||$key==='advertiser_emails') echo '<p class="description">Comma-separated email addresses.</p>';
        elseif($key==='admin_email') echo '<p class="description">Used for failure alerts when a run, workbook build, or email send fails.</p>';
        elseif($key==='report_timezone') echo '<p class="description">Timezone used to decide the report day and Square date window, for example America/Los_Angeles.</p>';
        elseif($key==='lookback_days') echo '<p class="description">How many previous days with the same film to refresh alongside the selected date. Use 0 to only update the selected day.</p>';
        elseif($key==='advertiser_schedule_day') echo '<p class="description">Day of the month to send last month\'s advertiser summary.</p>';
        return;
      case 'general_price': case 'discount_price': case 'group_price':
        echo '<input type="number" min="0" step="0.01" class="regular-text" name="'.esc_attr($name).'" value="'.esc_attr((string) $value).'">'; return;
      case 'schedule_enabled': case 'advertiser_schedule_enabled':
        $label=$key==='schedule_enabled'?'Send grosses reports automatically':'Send advertiser summary automatically each month';
        echo '<label><input type="checkbox" name="'.esc_attr($name).'" value="1" '.checked($value,'1',false).'> '.esc_html($label).'</label>'; return;
      case 'schedule_days':
        $days=is_array($value)?$value:[]; foreach(['mon'=>'Mon','tue'=>'Tue','wed'=>'Wed','thu'=>'Thu','fri'=>'Fri','sat'=>'Sat','sun'=>'Sun'] as $day=>$label){
          echo '<label style="display:inline-block; margin-right:12px;"><input type="checkbox" name="'.esc_attr($name).'[]" value="'.esc_attr($day).'" '.checked(in_array($day,$days,true),true,false).'> '.esc_html($label).'</label>';
        } return;
      case 'schedule_time': case 'advertiser_schedule_time':
        echo '<input type="time" name="'.esc_attr($name).'" value="'.esc_attr((string) $value).'">'; return;
    }
  }

  public static function render_page(): void {
    if(!roxy_suite_user_can_access_admin()) return;
    $status=self::get_status(); $timezone=new \DateTimeZone(self::get_report_timezone()); $default_date=wp_date('Y-m-d',null,$timezone); $default_advertiser_date=wp_date('Y-m-d',null,$timezone);
    $tab=self::current_tab(); $logs=Store::list_logs(100);
    echo '<div class="wrap"><h1>Roxy Grosses</h1><nav class="nav-tab-wrapper" style="margin-bottom:16px;">';
    echo '<a class="nav-tab '.($tab==='database'?'nav-tab-active':'').'" href="'.esc_url(admin_url('admin.php?page=roxy-grosses&tab=database')).'">Database</a>';
    echo '<a class="nav-tab '.($tab==='legacy-weekly'?'nav-tab-active':'').'" href="'.esc_url(admin_url('admin.php?page=roxy-grosses&tab=legacy-weekly')).'">Legacy Weekly</a>';
    echo '<a class="nav-tab '.($tab==='settings'?'nav-tab-active':'').'" href="'.esc_url(admin_url('admin.php?page=roxy-grosses&tab=settings')).'">Settings</a>';
    echo '<a class="nav-tab '.($tab==='logs'?'nav-tab-active':'').'" href="'.esc_url(admin_url('admin.php?page=roxy-grosses&tab=logs')).'">Logs</a></nav>';
    if(!empty($_GET['roxy_grosses_notice'])){ $notice=sanitize_text_field(wp_unslash((string) $_GET['roxy_grosses_notice'])); $message=isset($_GET['message'])?sanitize_text_field(wp_unslash((string) $_GET['message'])):''; echo '<div class="'.esc_attr($notice==='success'?'notice notice-success':'notice notice-error').'"><p>'.esc_html($message).'</p></div>'; }
    if(!empty($status['sent_at'])){ echo '<div class="notice notice-info"><p>Last activity: '.esc_html($status['report_date']?:'n/a').' | Time: '.esc_html($status['sent_at']).' | Mode: '.esc_html($status['mode']?:'n/a').' | Rows: '.esc_html((string) ($status['row_count']??0)).' | Gross: $'.esc_html(number_format((float) ($status['gross_total']??0),2)); if(!empty($status['message'])) echo ' | '.esc_html($status['message']); echo '</p></div>'; }
    if($tab==='settings'){ echo '<form method="post" action="options.php">'; settings_fields(self::OPTION_KEY); do_settings_sections('roxy-grosses'); submit_button('Save Grosses Settings'); echo '</form>'; self::render_test_tools($default_date,$default_advertiser_date); }
    elseif($tab==='logs'){ self::render_logs_tab($logs); }
    elseif($tab==='legacy-weekly'){ self::render_legacy_weekly_tab(); }
    else { self::render_database_tab($default_date); }
    echo '</div>';
  }

  private static function render_database_tab(string $default_date): void {
    $search = isset($_GET['search']) ? sanitize_text_field(wp_unslash((string) $_GET['search'])) : '';
    $year = isset($_GET['history_year']) ? max(0, (int) $_GET['history_year']) : 0;
    $month = isset($_GET['history_month']) ? sanitize_text_field(wp_unslash((string) $_GET['history_month'])) : '';
    $day = isset($_GET['history_day']) ? sanitize_text_field(wp_unslash((string) $_GET['history_day'])) : '';
    $filters = array_filter([
      'search' => $search,
      'year' => $year ?: null,
      'month' => $month,
      'day' => $day,
    ]);
    $rows = Store::list_entries($filters, 200, 0);
    $summary = Store::entries_summary($filters);
    $years = Store::distinct_years();

    echo '<h2>Database</h2><p>The grosses database is the source of truth. Pull from Square, then search by movie title, year, month, or day.</p>';
    echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'" style="display:flex; gap:12px; align-items:end; flex-wrap:wrap; margin-bottom:16px;">';
    wp_nonce_field('roxy_grosses_pull_database');
    echo '<input type="hidden" name="action" value="roxy_grosses_pull_database">';
    echo '<div><label for="roxy-grosses-pull-date"><strong>Manual pull date</strong></label><br><input id="roxy-grosses-pull-date" type="date" name="report_date" value="'.esc_attr($default_date).'"></div>';
    submit_button('Pull From Square','primary','submit',false);
    echo '</form>';

    echo '<form method="get" action="'.esc_url(admin_url('admin.php')).'" style="display:flex; gap:12px; align-items:end; flex-wrap:wrap; margin-bottom:16px;">';
    echo '<input type="hidden" name="page" value="roxy-grosses"><input type="hidden" name="tab" value="database">';
    echo '<div><label><strong>Search</strong></label><br><input type="text" class="regular-text" name="search" value="'.esc_attr($search).'" placeholder="Movie title"></div>';
    echo '<div><label><strong>Year</strong></label><br><select name="history_year"><option value="0">All years</option>';
    foreach ($years as $option_year) { echo '<option value="'.esc_attr((string) $option_year).'" '.selected($year, $option_year, false).'>'.esc_html((string) $option_year).'</option>'; }
    echo '</select></div>';
    echo '<div><label><strong>Month</strong></label><br><input type="month" name="history_month" value="'.esc_attr($month).'"></div>';
    echo '<div><label><strong>Day</strong></label><br><input type="date" name="history_day" value="'.esc_attr($day).'"></div>';
    submit_button('Filter','secondary','',false);
    echo '</form>';

    echo '<div style="display:flex; gap:16px; flex-wrap:wrap; margin:16px 0;">';
    foreach ([
      ['Rows', number_format_i18n((int) ($summary['row_count'] ?? 0))],
      ['Admissions', number_format_i18n((int) ($summary['admissions'] ?? 0))],
      ['Gross', '$' . number_format((float) ($summary['gross'] ?? 0), 2)],
      ['Avg Gross / Row', '$' . number_format((float) ($summary['average_gross'] ?? 0), 2)],
    ] as $card) {
      echo '<div style="min-width:200px; padding:16px; background:#fff; border:1px solid #dcdcde; border-radius:4px;"><div style="font-size:12px; color:#50575e; text-transform:uppercase;">'.esc_html($card[0]).'</div><div style="font-size:24px; font-weight:600; margin-top:8px;">'.esc_html($card[1]).'</div></div>';
    }
    echo '</div>';

    echo '<table class="widefat striped"><thead><tr><th>Date</th><th>Movie</th><th>Show Time</th><th>Total</th><th>General</th><th>Discount</th><th>Group</th><th>Live</th><th>Gross</th><th>Updated</th></tr></thead><tbody>';
    foreach ($rows as $row) {
      echo '<tr><td>'.esc_html((string) ($row['report_date'] ?? '')).'</td><td>'.esc_html((string) ($row['movie_title'] ?? '')).'</td><td>'.esc_html((string) ($row['show_time'] ?? '—')).'</td><td>'.esc_html(number_format_i18n((int) ($row['total_tickets'] ?? 0))).'</td><td>'.esc_html(number_format_i18n((int) ($row['general_qty'] ?? 0))).'</td><td>'.esc_html(number_format_i18n((int) ($row['discount_qty'] ?? 0))).'</td><td>'.esc_html(number_format_i18n((int) ($row['group_qty'] ?? 0))).'</td><td>'.esc_html(number_format_i18n((int) ($row['live_qty'] ?? 0))).'</td><td>$'.esc_html(number_format((float) ($row['gross_total'] ?? 0), 2)).'</td><td>'.esc_html((string) ($row['updated_at'] ?? '')).'</td></tr>';
    }
    if (!$rows) echo '<tr><td colspan="10">No grosses rows match the current filters.</td></tr>';
    echo '</tbody></table>';

    if ($rows) {
      echo '<h3 style="margin-top:24px;">Cleanup</h3><p>Delete individual rows when you spot a duplicate or bad import.</p>';
      echo '<table class="widefat striped" style="max-width:980px"><thead><tr><th>ID</th><th>Date</th><th>Movie</th><th>Show Time</th><th>Source</th><th>Action</th></tr></thead><tbody>';
      foreach ($rows as $row) {
        echo '<tr><td>'.esc_html((string) ($row['id'] ?? 0)).'</td><td>'.esc_html((string) ($row['report_date'] ?? '')).'</td><td>'.esc_html((string) ($row['movie_title'] ?? '')).'</td><td>'.esc_html((string) ($row['show_time'] ?? '-')).'</td><td>'.esc_html((string) ($row['source_type'] ?? '')).'</td><td>';
        echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'" onsubmit="return window.confirm(\'Delete this grosses row?\');" style="margin:0;">';
        wp_nonce_field('roxy_grosses_delete_entry');
        echo '<input type="hidden" name="action" value="roxy_grosses_delete_entry">';
        echo '<input type="hidden" name="entry_id" value="'.esc_attr((string) ($row['id'] ?? 0)).'">';
        echo '<input type="hidden" name="search" value="'.esc_attr($search).'">';
        echo '<input type="hidden" name="history_year" value="'.esc_attr((string) $year).'">';
        echo '<input type="hidden" name="history_month" value="'.esc_attr($month).'">';
        echo '<input type="hidden" name="history_day" value="'.esc_attr($day).'">';
        submit_button('Delete','link-delete','submit',false);
        echo '</form></td></tr>';
      }
      echo '</tbody></table>';
    }
  }

  private static function render_legacy_weekly_tab(): void {
    $search = isset($_GET['legacy_search']) ? sanitize_text_field(wp_unslash((string) $_GET['legacy_search'])) : '';
    $year = isset($_GET['legacy_year']) ? max(0, (int) $_GET['legacy_year']) : 0;
    $month = isset($_GET['legacy_month']) ? sanitize_text_field(wp_unslash((string) $_GET['legacy_month'])) : '';
    $day = isset($_GET['legacy_day']) ? sanitize_text_field(wp_unslash((string) $_GET['legacy_day'])) : '';
    $filters = array_filter([
      'search' => $search,
      'year' => $year ?: null,
      'month' => $month,
      'day' => $day,
    ]);
    $rows = Store::list_legacy_weekly($filters, 250, 0);
    $summary = Store::legacy_weekly_summary($filters);
    $years = Store::distinct_legacy_weekly_years();

    echo '<h2>Legacy Weekly</h2><p>This tab stores the previous owner\'s weekly attendance workbook as a separate historical dataset. It is weekly, multi-theater legacy data and does not affect the modern daily movie database.</p>';
    echo '<form method="get" action="'.esc_url(admin_url('admin.php')).'" style="display:flex; gap:12px; align-items:end; flex-wrap:wrap; margin-bottom:16px;">';
    echo '<input type="hidden" name="page" value="roxy-grosses"><input type="hidden" name="tab" value="legacy-weekly">';
    echo '<div><label><strong>Search</strong></label><br><input type="text" class="regular-text" name="legacy_search" value="'.esc_attr($search).'" placeholder="Movie title"></div>';
    echo '<div><label><strong>Year</strong></label><br><select name="legacy_year"><option value="0">All years</option>';
    foreach ($years as $option_year) { echo '<option value="'.esc_attr((string) $option_year).'" '.selected($year, $option_year, false).'>'.esc_html((string) $option_year).'</option>'; }
    echo '</select></div>';
    echo '<div><label><strong>Month</strong></label><br><input type="month" name="legacy_month" value="'.esc_attr($month).'"></div>';
    echo '<div><label><strong>Week Of</strong></label><br><input type="date" name="legacy_day" value="'.esc_attr($day).'"></div>';
    submit_button('Filter','secondary','',false);
    echo '</form>';

    echo '<div style="display:flex; gap:16px; flex-wrap:wrap; margin:16px 0;">';
    foreach ([
      ['Rows', number_format_i18n((int) ($summary['row_count'] ?? 0))],
      ['Attendance', number_format_i18n((int) ($summary['attendance'] ?? 0))],
      ['Paid', number_format_i18n((int) ($summary['paid'] ?? 0))],
      ['Free', number_format_i18n((int) ($summary['free'] ?? 0))],
    ] as $card) {
      echo '<div style="min-width:200px; padding:16px; background:#fff; border:1px solid #dcdcde; border-radius:4px;"><div style="font-size:12px; color:#50575e; text-transform:uppercase;">'.esc_html($card[0]).'</div><div style="font-size:24px; font-weight:600; margin-top:8px;">'.esc_html($card[1]).'</div></div>';
    }
    echo '</div>';

    echo '<table class="widefat striped"><thead><tr><th>Week Of</th><th>Week End</th><th>Movie</th><th>Rating</th><th>Weeks</th><th>General</th><th>Discount</th><th>Free</th><th>Total</th></tr></thead><tbody>';
    foreach ($rows as $row) {
      echo '<tr><td>'.esc_html((string) ($row['week_start_date'] ?? '')).'</td><td>'.esc_html((string) ($row['week_end_date'] ?? '')).'</td><td>'.esc_html((string) ($row['movie_title'] ?? '')).'</td><td>'.esc_html((string) ($row['rating'] ?? '')).'</td><td>'.esc_html((string) ($row['weeks_run'] ?? '')).'</td><td>'.esc_html(number_format_i18n((int) ($row['general_qty'] ?? 0))).'</td><td>'.esc_html(number_format_i18n((int) ($row['discount_qty'] ?? 0))).'</td><td>'.esc_html(number_format_i18n((int) ($row['free_qty'] ?? 0))).'</td><td>'.esc_html(number_format_i18n((int) ($row['total_attendance'] ?? 0))).'</td></tr>';
    }
    if (!$rows) echo '<tr><td colspan="9">No legacy weekly rows match the current filters.</td></tr>';
    echo '</tbody></table>';
  }

  private static function render_logs_tab(array $logs): void {
    echo '<h2>Logs</h2><p>Review database pulls, daily email sends, advertiser sends, and any errors.</p>';
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
    echo '<hr><h2>Email Tests</h2><p>Send a test email using the currently configured recipients and templates, as if the selected date were being processed normally.</p>';
    echo '<div style="display:flex; gap:24px; align-items:flex-start; flex-wrap:wrap;">';

    echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'" style="min-width:320px; padding:16px; background:#fff; border:1px solid #dcdcde; border-radius:4px;">';
    wp_nonce_field('roxy_grosses_send_manual');
    echo '<input type="hidden" name="action" value="roxy_grosses_send_manual">';
    echo '<input type="hidden" name="return_tab" value="settings">';
    echo '<input type="hidden" name="test_send" value="1">';
    echo '<h3 style="margin-top:0;">Test Daily Grosses Email</h3>';
    echo '<p>Select a report date and send the grosses email as if it were being run for that day.</p>';
    echo '<p><label for="roxy-grosses-test-date"><strong>Report date</strong></label><br><input id="roxy-grosses-test-date" type="date" name="report_date" value="'.esc_attr($default_date).'"></p>';
    submit_button('Send Test Grosses Email','secondary','submit',false);
    echo '</form>';

    echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'" style="min-width:360px; padding:16px; background:#fff; border:1px solid #dcdcde; border-radius:4px;">';
    wp_nonce_field('roxy_grosses_send_advertiser_summary');
    echo '<input type="hidden" name="action" value="roxy_grosses_send_advertiser_summary">';
    echo '<input type="hidden" name="return_tab" value="settings">';
    echo '<h3 style="margin-top:0;">Send Advertiser Email</h3>';
    echo '<p>Select a month range to send. For a single month, choose the same start and end month. For the last 12 months, choose the first and last months in that span.</p>';
    echo '<p><label for="roxy-grosses-advertiser-start-month"><strong>Start month</strong></label><br><input id="roxy-grosses-advertiser-start-month" type="month" name="advertiser_start_month" value="'.esc_attr($default_advertiser_start).'"></p>';
    echo '<p><label for="roxy-grosses-advertiser-end-month"><strong>End month</strong></label><br><input id="roxy-grosses-advertiser-end-month" type="month" name="advertiser_end_month" value="'.esc_attr($default_advertiser_end).'"></p>';
    submit_button('Send Advertiser Email','secondary','submit',false);
    echo '</form>';

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
  public static function sanitize_days($days): array {
    $allowed=['mon','tue','wed','thu','fri','sat','sun']; $days=is_array($days)?$days:[]; $clean=[];
    foreach($days as $day){ $day=strtolower(sanitize_text_field((string) $day)); if(in_array($day,$allowed,true)) $clean[]=$day; } return array_values(array_unique($clean));
  }
  public static function sanitize_time(string $time): string { return preg_match('/^\d{2}:\d{2}$/',$time)?$time:'20:00'; }
  public static function sanitize_timezone(string $timezone): string {
    $timezone=sanitize_text_field($timezone); try { new \DateTimeZone($timezone); return $timezone; } catch (\Exception $e) { return wp_timezone_string()?:'America/Los_Angeles'; }
  }
}
