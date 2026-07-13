(function () {
  function pad(n) {
    return n < 10 ? '0' + n : String(n);
  }

  function formatFriendly(isoLike) {
    if (!isoLike) return '';
    var normalized = String(isoLike).replace(' ', 'T');
    var date = new Date(normalized);
    if (Number.isNaN(date.getTime())) return isoLike;
    return date.toLocaleString([], {
      weekday: 'long',
      month: 'long',
      day: 'numeric',
      year: 'numeric',
      hour: 'numeric',
      minute: '2-digit'
    });
  }

  function minTargetDate() {
    var date = new Date();
    date.setDate(date.getDate() + Number(RoxyRS.minLeadDays || 30));
    return date.getFullYear() + '-' + pad(date.getMonth() + 1) + '-' + pad(date.getDate());
  }

  function setLoading(select) {
    select.innerHTML = '';
    var option = document.createElement('option');
    option.value = '';
    option.textContent = 'Loading available showtimes...';
    select.appendChild(option);
    select.disabled = true;
  }

  function setEmpty(select, message) {
    select.innerHTML = '';
    var option = document.createElement('option');
    option.value = '';
    option.textContent = message;
    select.appendChild(option);
    select.disabled = true;
  }

  function renderSlots(select, slots) {
    select.innerHTML = '';
    var placeholder = document.createElement('option');
    placeholder.value = '';
    placeholder.textContent = 'Choose a showtime';
    select.appendChild(placeholder);

    slots.forEach(function (slot) {
      var option = document.createElement('option');
      option.value = slot.target_at;
      option.textContent = slot.label;
      option.dataset.deadlineAt = slot.deadline_at || '';
      option.dataset.profile = slot.profile || 'movie_evening';
      select.appendChild(option);
    });

    select.disabled = false;
  }

  document.addEventListener('DOMContentLoaded', function () {
    var dateInput = document.getElementById('roxy-rs-target-date');
    var slotSelect = document.getElementById('roxy-rs-target-slot');
    var targetHidden = document.getElementById('roxy-rs-target-at');
    var deadlineLabel = document.getElementById('roxy-rs-deadline-label');

    if (!dateInput || !slotSelect || !targetHidden || !deadlineLabel || typeof RoxyRS === 'undefined') {
      return;
    }

    dateInput.min = minTargetDate();

    function syncSelection() {
      var option = slotSelect.options[slotSelect.selectedIndex];
      if (!option || !option.value) {
        targetHidden.value = '';
        deadlineLabel.textContent = 'Choose a showtime to calculate it.';
        return;
      }

      targetHidden.value = option.value;
      deadlineLabel.textContent = formatFriendly(option.dataset.deadlineAt || '');
    }

    async function loadSlots(dateValue) {
      if (!dateValue) {
        setEmpty(slotSelect, 'Choose a date first');
        syncSelection();
        return;
      }

      setLoading(slotSelect);
      deadlineLabel.textContent = 'Checking the calendar...';

      try {
        var url = new URL(RoxyRS.ajaxUrl);
        url.searchParams.set('action', 'roxy_rs_available_showtimes');
        url.searchParams.set('nonce', RoxyRS.nonce);
        url.searchParams.set('date', dateValue);

        var response = await fetch(url.toString(), { credentials: 'same-origin' });
        var data = await response.json();
        if (!data || !data.success) {
          throw new Error((data && data.data && data.data.message) || 'Could not load available showtimes.');
        }

        var slots = Array.isArray(data.data && data.data.slots) ? data.data.slots : [];
        if (!slots.length) {
          setEmpty(slotSelect, 'No available showtimes for that date');
          deadlineLabel.textContent = 'Try another date.';
          syncSelection();
          return;
        }

        renderSlots(slotSelect, slots);
        syncSelection();
      } catch (error) {
        setEmpty(slotSelect, 'Could not load showtimes');
        deadlineLabel.textContent = error && error.message ? error.message : 'Please try another date.';
        syncSelection();
      }
    }

    dateInput.addEventListener('change', function () {
      loadSlots(dateInput.value);
    });

    slotSelect.addEventListener('change', syncSelection);
  });
})();
