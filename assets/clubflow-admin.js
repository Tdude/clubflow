(function () {
  function sync(from, to, formatter) {
    if (!from || !to) {
      return;
    }
    from.addEventListener('change', function () {
      var value = from.value || '';
      to.value = formatter(value);
    });
  }

  function toManual(value) {
    return (value || '').replace('T', ' ').slice(0, 16);
  }

  function toPicker(value) {
    return (value || '').trim().replace(' ', 'T').slice(0, 16);
  }

  function pad2(n) {
    return String(n).padStart(2, '0');
  }

  function toDatetimeLocalValue(date) {
    if (!date) {
      return '';
    }
    var d = date instanceof Date ? date : new Date(date);
    return (
      d.getFullYear() +
      '-' +
      pad2(d.getMonth() + 1) +
      '-' +
      pad2(d.getDate()) +
      'T' +
      pad2(d.getHours()) +
      ':' +
      pad2(d.getMinutes())
    );
  }

  function qs(root, sel) {
    return (root || document).querySelector(sel);
  }

  function setText(el, txt) {
    if (el) {
      el.textContent = txt;
    }
  }

  function escapeHtml(str) {
    return String(str || '')
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#039;');
  }

  function initPostEditSync() {
    var startManual = document.getElementById('clubflow_start');
    var endManual = document.getElementById('clubflow_end');
    var startPicker = document.getElementById('clubflow_start_picker');
    var endPicker = document.getElementById('clubflow_end_picker');

    sync(startManual, startPicker, toPicker);
    sync(endManual, endPicker, toPicker);
    sync(startPicker, startManual, toManual);
    sync(endPicker, endManual, toManual);
  }

  function initAdminCalendar() {
    if (typeof window.ClubFlowAdmin === 'undefined') {
      return;
    }
    if (typeof window.FullCalendar === 'undefined') {
      return;
    }

    var root = document.getElementById('clubflow-admin-calendar');
    if (!root) {
      return;
    }

    var modal = document.getElementById('clubflow-admin-calendar-modal');
    var modalTitle = qs(modal, '.clubflow-admin-calendar-modal__title');
    var overlapBox = document.getElementById('clubflow-admin-calendar-overlap');
    var form = document.getElementById('clubflow-admin-calendar-form');
    var deleteBtn = qs(form, '[data-action="delete"]');
    var cancelBtn = qs(form, '[data-action="cancel"]');

    function openModal(opts) {
      if (!modal || !form) {
        return;
      }
      form.reset();
      if (overlapBox) {
        overlapBox.style.display = 'none';
        overlapBox.textContent = '';
      }

      var isEdit = !!opts.eventId;
      setText(modalTitle, isEdit ? ClubFlowAdmin.i18n.editEvent : ClubFlowAdmin.i18n.newEvent);

      form.elements.event_id.value = opts.eventId || '';
      form.elements.title.value = opts.title || '';
      form.elements.start.value = opts.start ? toDatetimeLocalValue(opts.start) : '';
      form.elements.end.value = opts.end ? toDatetimeLocalValue(opts.end) : '';
      form.elements.all_day.checked = !!opts.allDay;
      form.elements.location.value = opts.location || '';
      form.elements.booking_enabled.checked = !!opts.bookingEnabled;
      form.elements.max_spots.value = typeof opts.maxSpots !== 'undefined' ? String(opts.maxSpots) : '0';
      form.elements.price.value = opts.price || '';
      if (form.elements.category_id) {
        form.elements.category_id.value = opts.categoryId ? String(opts.categoryId) : '';
      }
      if (form.elements.instructor_id) {
        form.elements.instructor_id.value = opts.instructorId ? String(opts.instructorId) : '';
      }

      if (deleteBtn) {
        deleteBtn.style.display = isEdit ? '' : 'none';
      }
      modal.style.display = '';
    }

    function closeModal() {
      if (modal) {
        modal.style.display = 'none';
      }
    }

    function showOverlapWarning(overlaps) {
      if (!overlapBox) {
        return;
      }
      if (!overlaps || !overlaps.length) {
        overlapBox.style.display = 'none';
        overlapBox.textContent = '';
        return;
      }
      overlapBox.style.display = '';
      overlapBox.textContent = ClubFlowAdmin.i18n.overlapWarning;
    }

    function ajax(method, action, data) {
      var url = ClubFlowAdmin.ajaxUrl + '?action=' + encodeURIComponent(action);
      if (method === 'GET') {
        Object.keys(data || {}).forEach(function (k) {
          url += '&' + encodeURIComponent(k) + '=' + encodeURIComponent(data[k]);
        });
        return fetch(url, { credentials: 'same-origin' }).then(function (r) {
          return r.json();
        });
      }

      var body = new URLSearchParams();
      Object.keys(data || {}).forEach(function (k) {
        body.append(k, data[k]);
      });

      return fetch(url, {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
        body: body.toString(),
      }).then(function (r) {
        return r.json();
      });
    }

    function loadEvents(fetchInfo, success, failure) {
      ajax('GET', ClubFlowAdmin.actions.events, {
        nonce: ClubFlowAdmin.nonce,
        start: fetchInfo.startStr,
        end: fetchInfo.endStr,
      })
        .then(function (json) {
          if (!json || !json.success) {
            throw new Error((json && json.data && json.data.message) || ClubFlowAdmin.i18n.loadError);
          }
          success(json.data);
        })
        .catch(function () {
          failure(ClubFlowAdmin.i18n.loadError);
        });
    }

    var calendar = new FullCalendar.Calendar(root, {
      initialView: 'dayGridMonth',
      height: 'auto',
      timeZone: ClubFlowAdmin.timeZone || 'local',
      headerToolbar: {
        left: 'prev,next today',
        center: 'title',
        right: 'dayGridMonth',
      },
      selectable: true,
      selectMirror: true,
      editable: true,
      events: loadEvents,
      eventContent: function (arg) {
        var ext = arg.event.extendedProps || {};
        var booked = typeof ext.booked !== 'undefined' ? String(ext.booked) : '';
        var max = typeof ext.maxSpots !== 'undefined' && ext.maxSpots > 0 ? String(ext.maxSpots) : '';
        var counter = booked && max ? booked + '/' + max : booked;
        var title = arg.event.title || '';
        var html = '<div class="clubflow-admin-cal__event">';
        html += '<div class="clubflow-admin-cal__event-title">' + escapeHtml(title) + '</div>';
        if (counter) {
          html += '<div class="clubflow-admin-cal__event-counter">' + escapeHtml(counter) + '</div>';
        }
        html += '</div>';
        return { html: html };
      },
      eventClick: function (info) {
        info.jsEvent.preventDefault();
        var ext = info.event.extendedProps || {};
        openModal({
          eventId: info.event.id,
          title: info.event.title,
          start: info.event.start,
          end: info.event.end,
          allDay: info.event.allDay,
          location: ext.location || '',
          bookingEnabled: !!ext.bookingEnabled,
          maxSpots: ext.maxSpots || 0,
          price: ext.price || '',
          categoryId: ext.categoryId || 0,
          instructorId: ext.instructorId || 0,
        });
      },
      select: function (sel) {
        openModal({
          start: sel.start,
          end: sel.end,
          allDay: sel.allDay,
          bookingEnabled: true,
          maxSpots: 0,
        });
      },
      eventDrop: function () {
        calendar.refetchEvents();
      },
      eventResize: function () {
        calendar.refetchEvents();
      },
    });

    calendar.render();

    if (cancelBtn) {
      cancelBtn.addEventListener('click', function () {
        closeModal();
      });
    }
    if (modal) {
      var backdrop = qs(modal, '.clubflow-admin-calendar-modal__backdrop');
      if (backdrop) {
        backdrop.addEventListener('click', closeModal);
      }
      document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && modal.style.display !== 'none') {
          closeModal();
        }
      });
    }

    if (deleteBtn) {
      deleteBtn.addEventListener('click', function () {
        var eventId = form.elements.event_id.value;
        if (!eventId) {
          return;
        }
        ajax('POST', ClubFlowAdmin.actions.save, {
          nonce: ClubFlowAdmin.nonce,
          event_id: eventId,
          delete: '1',
        }).then(function () {
          closeModal();
          calendar.refetchEvents();
        });
      });
    }

    if (form) {
      form.addEventListener('submit', function (e) {
        e.preventDefault();

        ajax('POST', ClubFlowAdmin.actions.save, {
          nonce: ClubFlowAdmin.nonce,
          event_id: form.elements.event_id.value,
          title: form.elements.title.value,
          category_id: form.elements.category_id ? form.elements.category_id.value : '',
          instructor_id: form.elements.instructor_id ? form.elements.instructor_id.value : '',
          start: form.elements.start.value,
          end: form.elements.end.value,
          all_day: form.elements.all_day.checked ? '1' : '',
          location: form.elements.location.value,
          booking_enabled: form.elements.booking_enabled.checked ? '1' : '',
          max_spots: form.elements.max_spots.value,
          price: form.elements.price.value,
        })
          .then(function (json) {
            if (!json || !json.success) {
              throw new Error((json && json.data && json.data.message) || ClubFlowAdmin.i18n.saveError);
            }
            var overlaps = (json.data && json.data.overlaps) || [];
            if (overlaps.length) {
              showOverlapWarning(overlaps);
              calendar.refetchEvents();
              return;
            }

            closeModal();
            calendar.refetchEvents();
          })
          .catch(function () {
            alert(ClubFlowAdmin.i18n.saveError);
          });
      });
    }
  }

  function init() {
    initPostEditSync();
    initAdminCalendar();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
