(function($){
  function pad(n){ return (n<10?'0':'')+n; }
  let lastSelectedDateStr = null;

  function toYmd(d){ return d.getFullYear() + '-' + pad(d.getMonth()+1) + '-' + pad(d.getDate()); }
  function formatMoney(n){ return '$' + Number(n).toFixed(2); }
  function parseDateTime(mysql){
    var parts = mysql.split(' ');
    var d = parts[0].split('-').map(Number);
    var t = parts[1].split(':').map(Number);
    return new Date(d[0], d[1]-1, d[2], t[0], t[1], t[2]||0);
  }
  function rangesOverlap(aStart, aEnd, bStart, bEnd){ return aStart < bEnd && aEnd > bStart; }
  function hhmmToMinutes(hhmm){
    var m = /^(\d{2}):(\d{2})$/.exec(hhmm);
    if(!m) return null;
    return parseInt(m[1],10)*60 + parseInt(m[2],10);
  }

  function buildTimeOptions(dateObj, extraHours, blocks){
    var inc = Number(RoxyEB.incrementMinutes || 15);
    var openMin = hhmmToMinutes(RoxyEB.openTime || '08:00');
    var closeMin = hhmmToMinutes(RoxyEB.closeTime || '24:00');
    if (closeMin === 0 || RoxyEB.closeTime === '24:00' || closeMin === null) closeMin = 1440;

    var guestHours = 2 + Number(extraHours||0);
    var guestMinutes = guestHours * 60;
    var lastStart = closeMin - guestMinutes;
    if (lastStart < openMin) lastStart = openMin;

    var now = new Date();
    var leadHours = Number(RoxyEB.leadTimeHours || 48);
    var earliestAllowed = new Date(now.getTime() + leadHours*3600*1000);

    var options = [];
    var enabledCount = 0;
    for (var m = openMin; m <= lastStart; m += inc){
      var doorsOpen = new Date(dateObj.getFullYear(), dateObj.getMonth(), dateObj.getDate(), Math.floor(m/60), m%60, 0);
      var isDisabled = false;
      if (doorsOpen < earliestAllowed) isDisabled = true;

      var doorsClose = new Date(doorsOpen.getTime() + guestMinutes*60000);
      var reservedStart = new Date(doorsOpen.getTime() - 30*60000);
      var reservedEnd = new Date(doorsClose.getTime() + 30*60000);

      for (var i=0;i<blocks.length;i++){
        var b = blocks[i];
        if (rangesOverlap(reservedStart, reservedEnd, b.start, b.end)) { isDisabled = true; break; }
      }

      var label = doorsOpen.toLocaleTimeString([], {hour:'numeric', minute:'2-digit'});
      if (isDisabled) label += ' (Booked)';
      options.push({ value: pad(doorsOpen.getHours())+':'+pad(doorsOpen.getMinutes()), label: label, disabled: !!isDisabled });
      if (!isDisabled) enabledCount++;
    }
    options._enabledCount = enabledCount;
    return options;
  }

  function validBulkQty(n){
    n = Number(n||0);
    return n === 0 || (n >= 25 && n <= 250);
  }

  function coerceBulkQtyValue(raw){
    if (raw === '' || raw === null || typeof raw === 'undefined') return raw;
    var n = Number(raw);
    if (!Number.isFinite(n)) return raw;
    if (n <= 0) return 0;
    if (n > 0 && n < 25) return 25;
    if (n > 250) return 250;
    return Math.floor(n);
  }

  function maybeJumpBulkQty($input, previousValue){
    var raw = $input.val();
    if (raw === '') return;
    var currentValue = Number(raw);
    var prev = Number(previousValue || 0);
    if (!Number.isFinite(currentValue)) return;
    if (prev === 0 && currentValue > 0 && currentValue < 25) {
      $input.val('25');
    }
  }

  function computePricing(guestCount, extraHours, pizzaRequested, pizzaQuantity, bulkRequested, bulkPopcornQty, bulkSodaQty){
    guestCount = Number(guestCount||0);
    extraHours = Number(extraHours||0);
    pizzaRequested = Number(pizzaRequested||0);
    pizzaQuantity = Number(pizzaQuantity||0);
    var base = guestCount <= 25 ? Number(RoxyEB.prices.under) : Number(RoxyEB.prices.over);
    var extra = extraHours * Number(RoxyEB.prices.extra);
    var pizza = pizzaRequested ? pizzaQuantity * Number(RoxyEB.pizzaPrice || 18) : 0;
    var bulk = bulkRequested ? (Number(bulkPopcornQty||0) + Number(bulkSodaQty||0)) * Number(RoxyEB.bulkItemPrice || 3) : 0;
    return {base: base, extra: extra, pizza: pizza, bulk: bulk, total: base + extra + pizza + bulk};
  }

  function updateSubmitButton(){
    var customerType = $('#roxy-eb-customer-type').val();
    var paymentMethod = customerType === 'business' ? $('#roxy-eb-payment-method').val() : 'pay_now';
    $('#roxy-eb-submit-btn').text(paymentMethod === 'invoice' ? 'Submit booking request' : 'Continue to checkout');
  }

  function openModal(dateStr, blocks){
    var $m = $('#roxy-eb-modal');
    $m.attr('aria-hidden','false');
    var dateParts = dateStr.split('-').map(Number);
    var dayMidnight = new Date(dateParts[0], dateParts[1]-1, dateParts[2], 0,0,0);
    $m.data('dateStr', dateStr);
    $m.data('blocks', blocks);
    $('#roxy-eb-error').hide().text('');
    $('#roxy-eb-doors-open-at').val(dateStr + ' 00:00:00');

    var $date = $('#roxy-eb-date');
    if ($date.length){
      $date.val(dateStr);
      $date.off('change.roxy').on('change.roxy', async function(){
        var newDate = String($date.val() || '').trim();
        if (!newDate) return;
        lastSelectedDateStr = newDate;
        $m.data('dateStr', newDate);
        $('#roxy-eb-doors-open-at').val(newDate + ' 00:00:00');
        $('#roxy-eb-doors-open-time').val('');
        var newBlocksRaw = await fetchBlocks(newDate + ' 00:00:00', newDate + ' 23:59:59');
        var newBlocks = normalizeBlocks(newBlocksRaw);
        $m.data('blocks', newBlocks);
        var parts = newDate.split('-').map(Number);
        var midnight = new Date(parts[0], parts[1]-1, parts[2], 0,0,0);
        rebuildTimeOptions(midnight, newBlocks);
      });
    }

    rebuildTimeOptions(dayMidnight, blocks);
    toggleFormatFields();
    toggleCustomerFields();
    togglePizzaFields();
    updatePricingUI();
  }

  function rebuildTimeOptions(dayMidnight, blocks){
    var extraHours = Number($('#roxy-eb-extra-hours').val() || 0);
    var opts = buildTimeOptions(dayMidnight, extraHours, blocks);
    var $sel = $('#roxy-eb-doors-open-time');
    var cur = $sel.val();
    $sel.empty();
    if (!opts.length || (opts._enabledCount||0) === 0){
      $sel.append($('<option/>').val('').text('No available times'));
      $sel.prop('disabled', true);
    } else {
      $sel.prop('disabled', false);
      $sel.append($('<option/>').val('').text('Select a time'));
      opts.forEach(function(o){ $sel.append($('<option/>').val(o.value).text(o.label).prop('disabled', !!o.disabled)); });
      if (cur && opts.some(o => o.value === cur && !o.disabled)) $sel.val(cur);
    }
  }

  function closeModal(){ $('#roxy-eb-modal').attr('aria-hidden','true'); }

  function gatherBooking(){
    var dateStr = $('#roxy-eb-modal').data('dateStr');
    var timeVal = $('#roxy-eb-doors-open-time').val();
    if (!dateStr || !timeVal) return null;

    return {
      first_name: $('input[name="first_name"]').val().trim(),
      last_name: $('input[name="last_name"]').val().trim(),
      email: $('input[name="email"]').val().trim(),
      phone: $('input[name="phone"]').val().trim(),
      customer_type: $('#roxy-eb-customer-type').val(),
      business_name: $('input[name="business_name"]').val().trim(),
      payment_method: $('#roxy-eb-customer-type').val() === 'business' ? $('#roxy-eb-payment-method').val() : 'pay_now',
      guest_count: Number($('input[name="guest_count"]').val() || 0),
      doors_open_at: dateStr + ' ' + timeVal + ':00',
      extra_hours: Number($('#roxy-eb-extra-hours').val() || 0),
      event_format: $('#roxy-eb-event-format').val(),
      movie_title: $('input[name="movie_title"]').val().trim(),
      live_description: $('textarea[name="live_description"]').val().trim(),
      notes: $('textarea[name="notes"]').val().trim(),
      visibility: $('#roxy-eb-visibility').val(),
      pizza_requested: $('#roxy-eb-pizza-requested').val(),
      pizza_quantity: $('input[name="pizza_quantity"]').val(),
      pizza_order_details: $('textarea[name="pizza_order_details"]').val().trim(),
      bulk_concessions_requested: $('#roxy-eb-bulk-concessions-requested').val(),
      bulk_popcorn_qty: $('input[name="bulk_popcorn_qty"]').val(),
      bulk_soda_qty: $('input[name="bulk_soda_qty"]').val()
    };
  }

  function updatePricingUI(){
    var guestCount = Number($('input[name="guest_count"]').val() || 0);
    var extraHours = Number($('#roxy-eb-extra-hours').val() || 0);
    var pizzaRequested = Number($('#roxy-eb-pizza-requested').val() || 0);
    var pizzaQuantity = Number($('input[name="pizza_quantity"]').val() || 0);
    var bulkRequested = Number($('#roxy-eb-bulk-concessions-requested').val() || 0);
    var bulkPopcornQty = Number($('input[name="bulk_popcorn_qty"]').val() || 0);
    var bulkSodaQty = Number($('input[name="bulk_soda_qty"]').val() || 0);
    var customerType = $('#roxy-eb-customer-type').val();
    var paymentMethod = customerType === 'business' ? $('#roxy-eb-payment-method').val() : 'pay_now';
    var p = computePricing(guestCount || 1, extraHours, pizzaRequested, pizzaRequested ? (pizzaQuantity || 1) : 0, bulkRequested, bulkPopcornQty, bulkSodaQty);

    $('#roxy-eb-pricing').html(
      '<div><strong>' + (paymentMethod === 'invoice' ? 'Estimated total to invoice:' : 'Estimated total:') + '</strong> ' + formatMoney(p.total) + '</div>' +
      '<div style="margin-top:6px; font-size:13px; color:#555;">Event: ' + formatMoney(p.base + p.extra) +
      ' • Pizza: ' + formatMoney(p.pizza) + (pizzaRequested ? ' ($' + Number(RoxyEB.pizzaPrice || 18).toFixed(2) + ' each)' : '') +
      ' • Bulk concessions: ' + formatMoney(p.bulk) + (bulkRequested ? ' ($' + Number(RoxyEB.bulkItemPrice || 3).toFixed(2) + ' each)' : '') +
      '</div>'
    );

    var dateStr = $('#roxy-eb-modal').data('dateStr');
    var blocks = $('#roxy-eb-modal').data('blocks') || [];
    if (dateStr){
      var dp = dateStr.split('-').map(Number);
      rebuildTimeOptions(new Date(dp[0], dp[1]-1, dp[2], 0,0,0), blocks);
    }
    updateSubmitButton();
  }

  function toggleFormatFields(){
    var fmt = $('#roxy-eb-event-format').val();
    if (fmt === 'movie'){ $('#roxy-eb-movie-title-wrap').show(); $('#roxy-eb-live-desc-wrap').hide(); }
    else { $('#roxy-eb-movie-title-wrap').hide(); $('#roxy-eb-live-desc-wrap').show(); }
  }

  function toggleCustomerFields(){
    var type = $('#roxy-eb-customer-type').val();
    if (type === 'business'){
      $('#roxy-eb-business-name-wrap').show();
      $('#roxy-eb-payment-method-wrap').show();
    } else {
      $('#roxy-eb-business-name-wrap').hide();
      $('#roxy-eb-payment-method-wrap').hide();
      $('#roxy-eb-payment-method').val('pay_now');
    }
    updateSubmitButton();
  }

  function togglePizzaFields(){
    var pizza = $('#roxy-eb-pizza-requested').val() === '1';
    $('#roxy-eb-pizza-quantity-wrap').toggle(pizza);
    $('#roxy-eb-pizza-details-wrap').toggle(pizza);
  }

  function toggleBulkConcessionsFields(){
    var bulk = $('#roxy-eb-bulk-concessions-requested').val() === '1';
    $('#roxy-eb-bulk-popcorn-wrap').toggle(bulk);
    $('#roxy-eb-bulk-soda-wrap').toggle(bulk);
  }

  function fetchBlocks(startISO, endISO){
    return $.getJSON(RoxyEB.ajaxUrl, {
      action: 'roxy_eb_calendar_blocks',
      nonce: RoxyEB.nonce,
      start: startISO,
      end: endISO
    }).then(function(resp){
      if (!resp || !resp.success) throw new Error((resp && resp.data && resp.data.message) || 'Could not load availability');
      return resp.data.items || [];
    });
  }

  function toISODateTimeLocal(d){
    return d.getFullYear()+'-'+pad(d.getMonth()+1)+'-'+pad(d.getDate())+'T'+pad(d.getHours())+':'+pad(d.getMinutes())+':'+pad(d.getSeconds());
  }

  function normalizeBlocks(items){
    return items.map(function(it){
      return { kind: it.kind, title: it.title, visibility: it.visibility, doors_open_at: it.doors_open_at, start: parseDateTime(it.start), end: parseDateTime(it.end) };
    });
  }

  function formatVisibility(vis){ return (vis === 'public') ? 'Public' : 'Private'; }
  function parseMysqlToDate(mysql){ if (!mysql) return null; return parseDateTime(mysql); }

  $(function(){
    $(document).on('click', '[data-roxy-eb-close]', function(){ closeModal(); });
    $(document).on('keydown', function(e){ if(e.key === 'Escape') closeModal(); });

    $(document).on('change', '#roxy-eb-extra-hours, input[name="guest_count"], #roxy-eb-pizza-requested, input[name="pizza_quantity"], #roxy-eb-payment-method, #roxy-eb-bulk-concessions-requested, input[name="bulk_popcorn_qty"], input[name="bulk_soda_qty"]', updatePricingUI);
    $(document).on('focus', 'input[name="bulk_popcorn_qty"], input[name="bulk_soda_qty"]', function(){
      $(this).data('roxyPrevVal', $(this).val());
    });
    $(document).on('keydown', 'input[name="bulk_popcorn_qty"], input[name="bulk_soda_qty"]', function(e){
      if (e.key === 'ArrowUp' && Number($(this).val() || 0) === 0) {
        e.preventDefault();
        $(this).val('25').trigger('change');
      }
    });
    $(document).on('change', 'input[name="bulk_popcorn_qty"], input[name="bulk_soda_qty"]', function(){
      maybeJumpBulkQty($(this), $(this).data('roxyPrevVal'));
      $(this).data('roxyPrevVal', $(this).val());
      updatePricingUI();
    });
    $(document).on('blur', 'input[name="bulk_popcorn_qty"], input[name="bulk_soda_qty"]', function(){
      var coerced = coerceBulkQtyValue($(this).val());
      if (coerced !== $(this).val()) {
        $(this).val(coerced);
      }
      updatePricingUI();
    });
    $(document).on('change', '#roxy-eb-event-format', function(){ toggleFormatFields(); });
    $(document).on('change', '#roxy-eb-customer-type', function(){ toggleCustomerFields(); updatePricingUI(); });
    $(document).on('change', '#roxy-eb-pizza-requested', function(){ togglePizzaFields(); updatePricingUI(); });
    $(document).on('change', '#roxy-eb-bulk-concessions-requested', function(){ toggleBulkConcessionsFields(); updatePricingUI(); });
    toggleFormatFields();
    toggleCustomerFields();
    togglePizzaFields();
    toggleBulkConcessionsFields();
    updatePricingUI();

    $('#roxy-eb-form').on('submit', function(e){
      e.preventDefault();
      var booking = gatherBooking();
      if (!booking){
        $('#roxy-eb-error').show().text('Please select a date and time.');
        return;
      }
      var bulkRequested = String(booking.bulk_concessions_requested || '0') === '1';
      var bulkPopcornQty = Number(booking.bulk_popcorn_qty || 0);
      var bulkSodaQty = Number(booking.bulk_soda_qty || 0);
      if (bulkRequested && (!validBulkQty(bulkPopcornQty) || !validBulkQty(bulkSodaQty))) {
        $('#roxy-eb-error').show().text('Bulk concessions must be 0, or between 25 and 250 for each item.');
        return;
      }
      $('#roxy-eb-error').hide().text('');
      $('#roxy-eb-success').hide();
      $('#roxy-eb-submit-btn').prop('disabled', true).text('Working...');

      var action = (booking.customer_type === 'business' && booking.payment_method === 'invoice') ? 'roxy_eb_submit_invoice_booking' : 'roxy_eb_start_booking';

      $.post(RoxyEB.ajaxUrl, { action: action, nonce: RoxyEB.nonce, booking: booking })
        .done(function(resp){
          if (resp && resp.success && action === 'roxy_eb_submit_invoice_booking') {
            var successMsg = (resp && resp.data && resp.data.message) ? resp.data.message : 'Booking request submitted. Your time has been reserved.';
            $('#roxy-eb-form').hide();
            $('#roxy-eb-success-message').html('<strong>Booking request submitted.</strong><br>' + successMsg);
            $('#roxy-eb-success').show();
            return;
          }
          if (resp && resp.success && resp.data && resp.data.redirect){
            if (action === 'roxy_eb_submit_invoice_booking') {
              $('#roxy-eb-form').hide();
              $('#roxy-eb-success-message').html('<strong>Booking request submitted.</strong> Your time has been reserved. We will follow up with invoice details.');
              $('#roxy-eb-success').show();
              setTimeout(function(){ window.location.href = resp.data.redirect; }, 1600);
            } else {
              window.location.href = resp.data.redirect;
            }
          } else {
            var msg = (resp && resp.data && resp.data.message) ? resp.data.message : 'Could not continue.';
            $('#roxy-eb-error').show().text(msg);
          }
        }).fail(function(){
          $('#roxy-eb-error').show().text('Network error. Please try again.');
        }).always(function(){
          updateSubmitButton();
          $('#roxy-eb-submit-btn').prop('disabled', false);
        });
    });

    var el = document.getElementById('roxy-eb-calendar');
    if (!el || !window.FullCalendar) return;

    var calendar;
    calendar = new FullCalendar.Calendar(el, {
      initialView: 'dayGridMonth',
      height: 'auto',
      headerToolbar: { left: 'prev,next today', center: 'title', right: 'dayGridMonth,timeGridWeek' },
      buttonText: { dayGridMonth: 'month', timeGridWeek: 'week' },
      selectable: false,
      dayMaxEvents: true,
      nowIndicator: true,
      timeZone: 'local',
      eventDisplay: 'block',
      displayEventTime: false,
      events: function(fetchInfo, success, failure){
        var viewType = (calendar && calendar.view && calendar.view.type) ? calendar.view.type : '';
        fetchBlocks(toISODateTimeLocal(fetchInfo.start), toISODateTimeLocal(fetchInfo.end)).then(function(items){
          var ev = [];
          items.forEach(function(it){
            var bg = it.kind === 'showtime' ? 'rgba(255,193,7,0.22)' : 'rgba(108,117,125,0.18)';
            var labelStart = null;
            var visibility = it.visibility;
            if (it.kind === 'booking') labelStart = parseMysqlToDate(it.doors_open_at);
            else if (it.kind === 'block') labelStart = parseMysqlToDate(it.start);
            else if (it.kind === 'showtime') { labelStart = parseMysqlToDate(it.start); visibility = 'public'; }
            if (!labelStart) return;

            var title = formatVisibility(visibility);
            if (viewType === 'timeGridWeek') {
              var reservedStartDate = parseMysqlToDate(it.start);
              var reservedEndDate   = parseMysqlToDate(it.end);
              var doorsOpenDate = labelStart;
              ev.push({ title: '', start: reservedStartDate, end: reservedEndDate, allDay: false, display: 'background', backgroundColor: bg, borderColor: 'transparent' });
              ev.push({ title: title, start: doorsOpenDate, end: reservedEndDate, allDay: false, backgroundColor: '#4c8bf5', borderColor: 'transparent', textColor: '#111', classNames: ['roxy-eb-booking-fg'] });
            } else {
              ev.push({ title: '', start: it.start.replace(' ', 'T'), end: it.end.replace(' ', 'T'), display: 'background', backgroundColor: bg, borderColor: 'transparent' });
              var labelEnd = new Date(labelStart.getTime() + 15*60000);
              ev.push({ title: title, start: labelStart, end: labelEnd, allDay: false, backgroundColor: '#4c8bf5', borderColor: 'transparent', textColor: '#111' });
            }
          });
          success(ev);
        }).catch(function(err){ failure(err); });
      },
      dateClick: function(info){
        var day = info.date;
        var start = new Date(day.getFullYear(), day.getMonth(), day.getDate(), 0,0,0);
        var end = new Date(day.getFullYear(), day.getMonth(), day.getDate()+1, 0,0,0);
        fetchBlocks(toISODateTimeLocal(start), toISODateTimeLocal(end)).then(function(items){
          var blocks = normalizeBlocks(items);
          var dateStr = day.getFullYear()+'-'+pad(day.getMonth()+1)+'-'+pad(day.getDate());
          lastSelectedDateStr = dateStr;
          openModal(dateStr, blocks);
        }).catch(function(){ $('#roxy-eb-error').show().text('Could not load availability for that day. Please try again.'); });
      }
    });
    calendar.render();

    $(document).off('click.roxy', '#roxy-eb-book-now').on('click.roxy', '#roxy-eb-book-now', function(){
      var dateStr = lastSelectedDateStr || toYmd(calendar.getDate());
      lastSelectedDateStr = dateStr;
      fetchBlocks(dateStr + ' 00:00:00', dateStr + ' 23:59:59').then(function(items){ openModal(dateStr, normalizeBlocks(items)); }).catch(function(){ openModal(dateStr, []); });
    });
  });
})(jQuery);
