(function () {
  function qs(sel, root) {
    return (root || document).querySelector(sel);
  }

  function qsa(sel, root) {
    return Array.prototype.slice.call((root || document).querySelectorAll(sel));
  }

  function getModal() {
    return qs('.clubflow-modal');
  }

  var hoverCardEl = null;

  function removeHoverCard(immediate) {
    if (!hoverCardEl) {
      return;
    }
    var el = hoverCardEl;
    hoverCardEl = null;

    if (immediate) {
      if (el.parentNode) {
        el.parentNode.removeChild(el);
      }
      return;
    }

    el.classList.remove('is-visible');
    el.classList.add('is-leaving');
    window.setTimeout(function () {
      if (el.parentNode) {
        el.parentNode.removeChild(el);
      }
    }, 180);
  }

  function showHoverCard(sourceEl) {
    removeHoverCard(true);

    if (!sourceEl || !sourceEl.getBoundingClientRect) {
      return;
    }

    var rect = sourceEl.getBoundingClientRect();
    var listRow = null;
    if (sourceEl && sourceEl.tagName === 'TR' && sourceEl.classList && sourceEl.classList.contains('fc-list-event')) {
      listRow = sourceEl;
    } else if (sourceEl && sourceEl.closest) {
      var maybeTr = sourceEl.closest('tr.fc-list-event');
      if (maybeTr && maybeTr.closest && maybeTr.closest('.fc-list')) {
        listRow = maybeTr;
      }
    }

    if (listRow) {
      hoverCardEl = document.createElement('div');
      hoverCardEl.className = 'clubflow-hovercard';
      hoverCardEl.style.position = 'fixed';
      hoverCardEl.style.left = rect.left + 'px';
      hoverCardEl.style.top = rect.top + 'px';
      hoverCardEl.style.width = rect.width + 'px';
      hoverCardEl.style.zIndex = '100000';
      hoverCardEl.style.display = 'block';

      var table = document.createElement('table');
      table.className = 'fc-list-table';
      var tbody = document.createElement('tbody');
      var rowClone = listRow.cloneNode(true);
      tbody.appendChild(rowClone);
      table.appendChild(tbody);
      hoverCardEl.appendChild(table);
    } else {
      hoverCardEl = sourceEl.cloneNode(true);
      hoverCardEl.classList.add('clubflow-hovercard');
      hoverCardEl.style.position = 'fixed';
      hoverCardEl.style.left = rect.left + 'px';
      hoverCardEl.style.top = rect.top + 'px';
      hoverCardEl.style.width = rect.width + 'px';
      hoverCardEl.style.zIndex = '100000';
      hoverCardEl.style.display = 'flex';
    }

    var origDot = sourceEl.querySelector('.fc-list-event-dot');
    var cloneDot = hoverCardEl.querySelector('.fc-list-event-dot');
    if (origDot && cloneDot) {
      var dotColor = origDot.style.borderColor || window.getComputedStyle(origDot).borderColor;
      if (dotColor) {
        cloneDot.style.borderColor = dotColor;
      }
    }

    document.body.appendChild(hoverCardEl);
    window.requestAnimationFrame(function () {
      if (hoverCardEl) {
        hoverCardEl.classList.add('is-visible');
      }
    });
  }

  function renderLegend(calEl, events) {
    try {
      if (!calEl) {
        return;
      }
      var table = qs('.fc-list-table', calEl);
      if (!table) {
        return;
      }

      var existing = qs('[data-clubflow-legend]', calEl);
      if (existing && existing.parentNode) {
        existing.parentNode.removeChild(existing);
      }

      var map = {};
      var isUncatMap = {};
      (events || []).forEach(function (ev) {
        var name = ev && ev.extendedProps ? ev.extendedProps.categoryName : '';
        var color = ev && ev.extendedProps ? ev.extendedProps.dotColor : '';
        var isUncat = !!(ev && ev.extendedProps && ev.extendedProps.isUncategorized);
        if (!name || !color) {
          return;
        }
        name = String(name).trim();
        color = String(color).trim();
        if (!name || !color) {
          return;
        }
        if (!map[name]) {
          map[name] = color;
          isUncatMap[name] = isUncat;
        }
      });

      var names = Object.keys(map);
      if (!names.length) {
        return;
      }

      names.sort(function (a, b) {
        var au = !!isUncatMap[a];
        var bu = !!isUncatMap[b];
        if (au !== bu) {
          return au ? 1 : -1;
        }
        return a.localeCompare(b);
      });

      var wrap = document.createElement('div');
      wrap.setAttribute('data-clubflow-legend', '1');
      wrap.className = 'clubflow-legend';

      names.forEach(function (name) {
        var item = document.createElement('span');
        item.className = 'clubflow-legend__item';
        var dot = document.createElement('span');
        dot.className = 'clubflow-legend__dot';
        dot.style.borderColor = map[name];
        dot.title = name;
        var label = document.createElement('span');
        label.className = 'clubflow-legend__label';
        label.textContent = name;
        item.appendChild(dot);
        item.appendChild(label);
        wrap.appendChild(item);
      });

      table.parentNode.insertBefore(wrap, table);
    } catch (e) {}
  }

  function normalizeListView(calEl) {
    try {
      if (!calEl) {
        return;
      }
      qsa('tr.fc-list-day', calEl).forEach(function (dayRow) {
        dayRow.style.display = 'none';
      });
    } catch (e) {}
  }

  function decorateListEventRow(info) {
    try {
      if (!info || !info.el || !info.event) {
        return;
      }

      var tr = info.el.closest('tr');
      if (!tr || !tr.classList.contains('fc-list-event')) {
        return;
      }
      if (!tr.closest('.fc-list')) {
        return;
      }

      qsa('[data-clubflow-list-excerpt]', tr).forEach(function (el) {
        if (el.parentNode) {
          el.parentNode.removeChild(el);
        }
      });

      var timeCell = qs('td.fc-list-event-time', tr);
      if (timeCell) {
        var startStr = info.event.startStr || '';
        var dateStr = startStr.split('T')[0];
        var restText = (info.timeText || '').trim();
        timeCell.textContent = '';
        var strong = document.createElement('span');
        strong.style.fontWeight = '600';
        strong.textContent = dateStr;
        timeCell.appendChild(strong);
        if (restText) {
          timeCell.appendChild(document.createTextNode(' ' + restText));
        }
      }

      var titleCell = qs('td.fc-list-event-title', tr) || qs('.fc-list-event-title', tr);
      if (titleCell) {
        var titleLink = qs('a', titleCell);
        if (titleLink) {
          titleLink.classList.add('clubflow-fc-title');
        }

        var catName = info.event.extendedProps && info.event.extendedProps.categoryName ? String(info.event.extendedProps.categoryName) : '';
        var dotColor = info.event.extendedProps && info.event.extendedProps.dotColor ? String(info.event.extendedProps.dotColor) : '';
        catName = catName.trim();
        dotColor = dotColor.trim();

        var dotEl = qs('.fc-list-event-dot', tr);
        var graphicCell = qs('td.fc-list-event-graphic', tr);
        if (dotEl && dotColor) {
          dotEl.style.display = '';
          dotEl.style.borderColor = dotColor;
          dotEl.style.borderTopColor = dotColor;
          dotEl.style.borderRightColor = dotColor;
          dotEl.style.borderBottomColor = dotColor;
          dotEl.style.borderLeftColor = dotColor;
        } else if (dotEl) {
          dotEl.style.display = 'none';
        }
        if (dotEl && catName) {
          dotEl.title = catName;
        }

        var excerpt = info.event.extendedProps && info.event.extendedProps.excerpt ? String(info.event.extendedProps.excerpt) : '';
        excerpt = excerpt.trim();
        if (excerpt) {
          var ex = document.createElement('div');
          ex.setAttribute('data-clubflow-list-excerpt', '1');
          ex.className = 'clubflow-fc-excerpt';
          ex.style.opacity = '0.85';
          ex.style.marginTop = '2px';
          ex.textContent = excerpt;
          titleCell.appendChild(ex);
        }
      }
    } catch (e) {}
  }

  function closeModal() {
    var modal = getModal();
    if (!modal) {
      return;
    }

    modal.style.display = 'none';
    modal.setAttribute('aria-hidden', 'true');
    document.body.classList.remove('clubflow-modal-open');

    var content = qs('[data-clubflow-modal-content]', modal);
    if (content) {
      content.innerHTML = '';
    }
  }

  function parseAmountAttr(form, name) {
    try {
      var v = form && form.getAttribute ? form.getAttribute(name) : '';
      if (v === null || v === undefined) {
        return 0;
      }
      var n = parseFloat(String(v).replace(',', '.'));
      return isNaN(n) ? 0 : n;
    } catch (e) {
      return 0;
    }
  }

  function getSelectedAmount(form) {
    var baseAmount = parseAmountAttr(form, 'data-clubflow-price-amount');
    var memberAmount = parseAmountAttr(form, 'data-clubflow-member-price-amount');
    var memberChoice = qs('input[name="is_member"]:checked', form);
    if (memberChoice && memberChoice.value === '1') {
      return memberAmount;
    }
    return baseAmount;
  }

  function setRequired(el, required) {
    if (!el) return;
    if (required) {
      el.setAttribute('required', 'required');
    } else {
      el.removeAttribute('required');
    }
  }

  function updateFreeFields(form) {
    if (!form) return;

    var amount = getSelectedAmount(form);
    var isFree = amount <= 0;

    qsa('[data-clubflow-free-field]', form).forEach(function (wrap) {
      wrap.style.display = isFree ? '' : 'none';
    });

    qsa('[data-clubflow-free-required]', form).forEach(function (input) {
      setRequired(input, isFree);
    });
  }

  function openModal(html) {
    var modal = getModal();
    if (!modal) {
      return;
    }

    // Blur any focused element (prevents clicked event from staying on top)
    if (document.activeElement && document.activeElement.blur) {
      document.activeElement.blur();
    }

    // Add body class for CSS targeting
    document.body.classList.add('clubflow-modal-open');

    var content = qs('[data-clubflow-modal-content]', modal);
    if (content) {
      content.innerHTML = html;
    }

    try {
      var injectedForm = content ? qs('[data-clubflow-booking-form]', content) : null;
      if (injectedForm) {
        updateFreeFields(injectedForm);
      }
    } catch (e) {}

    modal.style.display = 'block';
    modal.setAttribute('aria-hidden', 'false');
  }

  function initModal() {
    var modal = getModal();
    if (!modal) {
      return;
    }

    qsa('[data-clubflow-modal-close]', modal).forEach(function (btn) {
      btn.addEventListener('click', function () {
        closeModal();
      });
    });

    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape') {
        closeModal();
      }
    });

    // Handle booking form submission (delegated)
    modal.addEventListener('submit', function (e) {
      var form = e.target.closest('[data-clubflow-booking-form]');
      if (!form) {
        return;
      }
      e.preventDefault();
      handleBookingSubmit(form);
    });
  }

  var paymentPollInterval = null;

  function startPaymentPolling(checkUrl, containerEl) {
    if (paymentPollInterval) {
      clearInterval(paymentPollInterval);
    }

    var pollCount = 0;
    var maxPolls = 120; // 10 minutes at 5 second intervals

    paymentPollInterval = setInterval(function () {
      pollCount++;
      
      if (pollCount > maxPolls) {
        clearInterval(paymentPollInterval);
        updatePaymentStatus(containerEl, 'timeout');
        return;
      }

      fetch(checkUrl, { credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (data) {
          if (data && data.success && data.data) {
            if (data.data.paid || data.data.status === 'PAID') {
              clearInterval(paymentPollInterval);
              updatePaymentStatus(containerEl, 'paid');
            } else if (data.data.status === 'DECLINED' || data.data.status === 'ERROR' || data.data.status === 'CANCELLED') {
              clearInterval(paymentPollInterval);
              updatePaymentStatus(containerEl, 'failed');
            }
          }
        })
        .catch(function () {
          // Ignore errors, keep polling
        });
    }, 5000); // Poll every 5 seconds
  }

  function updatePaymentStatus(containerEl, status) {
    var statusEl = qs('.clubflow-payment-status', containerEl);
    if (!statusEl) return;

    statusEl.setAttribute('data-status', status);
    var textEl = qs('.clubflow-status-text', statusEl);
    var spinnerEl = qs('.clubflow-status-spinner', statusEl);

    if (status === 'paid') {
      if (spinnerEl) spinnerEl.style.display = 'none';
      statusEl.className = 'clubflow-payment-status clubflow-payment-status--success';
      if (textEl) textEl.innerHTML = '✓ ' + (window.ClubFlow.i18n.paymentConfirmed || 'Betalning bekräftad!');
      
      // Hide QR/button after success
      var qrEl = qs('.clubflow-swish-qr', containerEl);
      var btnEl = qs('.clubflow-swish-button', containerEl);
      if (qrEl) qrEl.style.display = 'none';
      if (btnEl) btnEl.style.display = 'none';
    } else if (status === 'failed') {
      if (spinnerEl) spinnerEl.style.display = 'none';
      statusEl.className = 'clubflow-payment-status clubflow-payment-status--error';
      if (textEl) textEl.innerHTML = '✗ ' + (window.ClubFlow.i18n.paymentFailed || 'Betalning misslyckades');
    } else if (status === 'timeout') {
      if (spinnerEl) spinnerEl.style.display = 'none';
      statusEl.className = 'clubflow-payment-status clubflow-payment-status--warning';
      if (textEl) textEl.innerHTML = (window.ClubFlow.i18n.paymentTimeout || 'Betalningen kunde inte bekräftas. Kontakta oss om du har betalat.');
    }
  }

  function handleBookingSubmit(form) {
    if (!window.ClubFlow) {
      return;
    }

    updateFreeFields(form);

    var submitBtn = qs('button[type="submit"]', form);
    var messageEl = qs('[data-clubflow-booking-message]', form.parentNode);
    
    if (submitBtn) {
      submitBtn.disabled = true;
      submitBtn.textContent = window.ClubFlow.i18n.booking || 'Booking...';
    }

    if (messageEl) {
      messageEl.style.display = 'none';
      messageEl.className = 'clubflow-booking__message';
      messageEl.textContent = '';
    }

    var formData = new FormData(form);
    formData.append('action', window.ClubFlow.actionBook);
    formData.append('_ajax_nonce', window.ClubFlow.nonceBook);
    formData.append('return_url', window.location.href);

    fetch(window.ClubFlow.ajaxUrl, {
      method: 'POST',
      credentials: 'same-origin',
      body: formData
    })
      .then(function (r) {
        return r.json();
      })
      .then(function (data) {
        if (data && data.success) {
          // Success
          form.style.display = 'none';
          if (messageEl) {
            messageEl.className = 'clubflow-booking__message clubflow-booking__message--success';
            
            var html = '<strong>' + (window.ClubFlow.i18n.booked || 'Booked!') + '</strong><br>' +
              (window.ClubFlow.i18n.bookingSuccess || 'Your booking is confirmed! Confirmation code:') + 
              ' <strong>' + (data.data.confirmation_code || '') + '</strong>';
            
            // Show payment info if present
            if (data.data.payment) {
              var p = data.data.payment;
              console.log('Payment data:', p);  // DEBUG
              html += '<div class="clubflow-payment-info" data-payment-reference="' + (p.reference || '') + '" data-check-url="' + (p.check_url || '') + '">';
              
              if (p.method === 'stripe' && p.checkout_url) {
                // Stripe Checkout - redirect
                html += '<div class="clubflow-stripe-checkout">';
                html += '<p>' + (window.ClubFlow.i18n.redirectingToPayment || 'Skickar dig till betalning...') + '</p>';
                html += '<a href="' + p.checkout_url + '" class="clubflow-stripe-button">' +
                  (window.ClubFlow.i18n.proceedToPayment || 'Gå till betalning') + ' →</a>';
                html += '</div>';
                
                // Auto-redirect after short delay
                setTimeout(function() {
                  window.location.href = p.checkout_url;
                }, 1000);
                
              } else if (p.method === 'klarna' && p.html_snippet) {
                // Klarna checkout - embed their widget
                html += '<div class="clubflow-klarna-checkout">';
                html += '<div class="clubflow-klarna-header">';
                html += '<span class="clubflow-klarna-amount">' + p.amount + ' ' + (p.currency || 'SEK') + '</span>';
                html += '</div>';
                html += '<div id="klarna-checkout-container">' + p.html_snippet + '</div>';
                html += '</div>';
                
              } else if (p.method === 'swish') {
                var isMobile = /iPhone|iPad|iPod|Android/i.test(navigator.userAgent);
                
                html += '<div class="clubflow-swish-header">';
                html += '<img src="https://www.swish.nu/images/swish-logo.svg" alt="Swish" class="clubflow-swish-logo" />';
                html += '<span class="clubflow-swish-amount">' + p.amount + ' ' + (p.currency || 'SEK') + '</span>';
                html += '</div>';
                
                if (isMobile && p.deep_link) {
                  // Mobile: Show button to open Swish app
                  html += '<a href="' + p.deep_link + '" class="clubflow-swish-button">';
                  html += (window.ClubFlow.i18n.openSwish || 'Öppna Swish');
                  html += '</a>';
                  html += '<p class="clubflow-swish-manual">';
                  html += (window.ClubFlow.i18n.orPayManually || 'Eller betala manuellt till:') + ' <strong>' + p.swish_number + '</strong>';
                  html += '</p>';
                } else if (p.qr_url) {
                  // Desktop: Show QR code
                  html += '<div class="clubflow-swish-qr">';
                  html += '<p>' + (window.ClubFlow.i18n.scanQR || 'Skanna QR-koden med Swish-appen:') + '</p>';
                  html += '<img src="' + p.qr_url + '" alt="Swish QR" class="clubflow-qr-image" />';
                  html += '</div>';
                  html += '<p class="clubflow-swish-manual">';
                  html += (window.ClubFlow.i18n.orPayManually || 'Eller betala manuellt till:') + ' <strong>' + p.swish_number + '</strong>';
                  html += '</p>';
                } else {
                  // Fallback: Just show number
                  html += '<p>' + (window.ClubFlow.i18n.payToNumber || 'Betala till:') + ' <strong>' + p.swish_number + '</strong></p>';
                }
                
                html += '<p class="clubflow-swish-ref">' + (window.ClubFlow.i18n.swishMessage || 'Meddelande:') + ' <code>' + p.reference + '</code></p>';
                
                // Payment status indicator
                html += '<div class="clubflow-payment-status" data-status="pending">';
                html += '<span class="clubflow-status-spinner"></span> ';
                html += '<span class="clubflow-status-text">' + (window.ClubFlow.i18n.awaitingPayment || 'Väntar på betalning...') + '</span>';
                html += '</div>';
                
              } else if (p.method === 'manual') {
                html += '<strong>' + p.message + '</strong><br>';
                html += (window.ClubFlow.i18n.amount || 'Belopp:') + ' ' + p.amount;
              }
              
              html += '</div>';
            }
            
            messageEl.innerHTML = html;
            messageEl.style.display = 'block';

            // If server provided a redirect URL (e.g. free bookings), navigate there
            // so the PHP confirmation toast can show.
            if (data.data && data.data.redirect_url) {
              window.location.href = data.data.redirect_url;
              return;
            }
            
            // Start polling for payment status if Swish
            if (data.data.payment && data.data.payment.method === 'swish' && data.data.payment.check_url) {
              startPaymentPolling(data.data.payment.check_url, messageEl);
            }
          }
        } else {
          // Error
          if (submitBtn) {
            submitBtn.disabled = false;
            submitBtn.textContent = window.ClubFlow.i18n.bookNow || 'Book now';
          }
          if (messageEl) {
            messageEl.className = 'clubflow-booking__message clubflow-booking__message--error';
            messageEl.textContent = (data && data.data) ? data.data : (window.ClubFlow.i18n.bookingError || 'Booking failed.');
            messageEl.style.display = 'block';
          }
        }
      })
      .catch(function () {
        if (submitBtn) {
          submitBtn.disabled = false;
          submitBtn.textContent = window.ClubFlow.i18n.bookNow || 'Book now';
        }
        if (messageEl) {
          messageEl.className = 'clubflow-booking__message clubflow-booking__message--error';
          messageEl.textContent = window.ClubFlow.i18n.bookingError || 'Booking failed.';
          messageEl.style.display = 'block';
        }
      });
  }

  function ensureSvLocale() {
    if (!window.FullCalendar) {
      return;
    }

    window.FullCalendar.globalLocales = window.FullCalendar.globalLocales || [];
    window.FullCalendar.globalLocales.push({
      code: 'sv',
      week: { dow: 1, doy: 4 },
      buttonText: {
        prev: 'Föregående',
        next: 'Nästa',
        today: 'Idag',
        month: 'Månad',
        week: 'Vecka',
        day: 'Dag',
        list: 'Lista'
      },
      weekText: 'V',
      allDayText: 'Hela dagen',
      moreLinkText: function (n) {
        return '+' + n + ' till';
      },
      noEventsText: 'Inga händelser att visa'
    });
  }

  function initOne(el) {
    if (!window.FullCalendar || !window.ClubFlow) {
      return;
    }

    function toDateOnly(d) {
      var y = d.getFullYear();
      var m = String(d.getMonth() + 1).padStart(2, '0');
      var day = String(d.getDate()).padStart(2, '0');
      return y + '-' + m + '-' + day;
    }

    function parseIsoDate(iso) {
      try {
        return iso ? new Date(iso) : null;
      } catch (e) {
        return null;
      }
    }

    function expandMultiDayEventsForList(events) {
      var out = [];
      (events || []).forEach(function (ev) {
        if (!ev || !ev.start) {
          return;
        }

        var start = parseIsoDate(ev.start);
        var end = ev.end ? parseIsoDate(ev.end) : null;
        if (!start || isNaN(start.getTime())) {
          return;
        }

        var startDay = toDateOnly(start);
        var endDay = end && !isNaN(end.getTime()) ? toDateOnly(end) : '';

        // Single-day or no end => keep as-is
        if (!end || !endDay || endDay === startDay) {
          out.push(ev);
          return;
        }

        // FullCalendar allDay end is typically exclusive (end at 00:00 of next day).
        // We aim to show one list row per calendar day.
        var dayCursor = new Date(start.getFullYear(), start.getMonth(), start.getDate());
        var lastDay = new Date(end.getFullYear(), end.getMonth(), end.getDate());

        if (ev.allDay && end.getHours() === 0 && end.getMinutes() === 0 && end.getSeconds() === 0) {
          lastDay.setDate(lastDay.getDate() - 1);
        }

        var originalId = ev.id;

        while (dayCursor <= lastDay) {
          var clone = {};
          for (var k in ev) {
            clone[k] = ev[k];
          }

          var dayIso = toDateOnly(dayCursor) + 'T00:00:00';
          clone.start = dayIso;
          delete clone.end;

          clone.id = String(originalId) + '__' + toDateOnly(dayCursor);
          clone.allDay = true;
          clone.extendedProps = clone.extendedProps || {};
          clone.extendedProps.originalId = originalId;

          out.push(clone);
          dayCursor.setDate(dayCursor.getDate() + 1);
        }
      });

      return out;
    }

    var category = el.getAttribute('data-category') || '';
    var initialView = el.getAttribute('data-view') || 'dayGridMonth';
    var initialDate = el.getAttribute('data-initial-date') || '';
    var listMonths = parseInt(el.getAttribute('data-list-months') || '3', 10);
    if (isNaN(listMonths) || listMonths < 1) {
      listMonths = 3;
    }
    if (listMonths > 12) {
      listMonths = 12;
    }

    var listDuration = { months: listMonths };
    var listButtonText = listMonths === 1 ? '1 månad' : listMonths + ' månader';

    var isMobile = window.innerWidth <= 640;
    var mobileToolbar = { left: 'prev,next', center: 'title', right: 'today' };
    var desktopToolbar = { left: 'prev,next today', center: 'title', right: 'dayGridMonth,listRange' };
    var calendar = new FullCalendar.Calendar(el, {
      headerToolbar: isMobile ? mobileToolbar : desktopToolbar,
      titleFormat: isMobile ? { month: 'long' } : { year: 'numeric', month: 'long' },
      locale: 'sv',
      firstDay: 1,
      views: {
        listRange: {
          type: 'list',
          duration: listDuration,
          buttonText: listButtonText
        },
        listTwoWeeks: {
          type: 'list',
          duration: { weeks: 2 },
          buttonText: '2 veckor'
        }
      },
      buttonText: { today: 'Idag', month: 'Månad', week: 'Vecka', day: 'Dag', list: 'Lista', dayGridMonth: 'Månad' },
      height: 'auto',
      expandRows: true,
      initialView: (function() {
        var view = initialView === 'listWeek' ? 'listRange' : initialView;
        // Mobile: override month grid to 2-week list
        if (isMobile && view === 'dayGridMonth') {
          return 'listTwoWeeks';
        }
        return view;
      })(),
      initialDate: initialDate || undefined,
      datesSet: function () {
        window.setTimeout(function () {
          normalizeListView(el);
        }, 0);
      },
      eventsSet: function (events) {
        window.setTimeout(function () {
          normalizeListView(el);
        }, 0);
        window.setTimeout(function () {
          renderLegend(el, events);
        }, 0);
      },
      eventMouseEnter: function (info) {
        try {
          if (!info || !info.el) {
            return;
          }
          showHoverCard(info.el);
        } catch (e) {}
      },
      eventMouseLeave: function () {
        removeHoverCard(false);
      },
      eventDidMount: function (info) {
        window.setTimeout(function () {
          normalizeListView(el);
        }, 0);
        window.setTimeout(function () {
          decorateListEventRow(info);
        }, 0);
      },
      eventClick: function (info) {
        if (info && info.jsEvent) {
          info.jsEvent.preventDefault();
        }

        var eventId = info && info.event ? info.event.id : null;
        if (info && info.event && info.event.extendedProps && info.event.extendedProps.originalId) {
          eventId = info.event.extendedProps.originalId;
        }
        if (!eventId) {
          return;
        }

        openModal('<p>Loading...</p>');

        var url = new URL(window.ClubFlow.ajaxUrl);
        url.searchParams.set('action', window.ClubFlow.actionDetails);
        url.searchParams.set('_ajax_nonce', window.ClubFlow.nonceDetails);
        url.searchParams.set('event_id', eventId);

        fetch(url.toString(), { credentials: 'same-origin' })
          .then(function (r) {
            return r.json();
          })
          .then(function (data) {
            if (data && data.success && data.data && data.data.html) {
              openModal(data.data.html);
            } else {
              openModal('<p>Could not load event.</p>');
            }
          })
          .catch(function () {
            openModal('<p>Could not load event.</p>');
          });
      },
      events: function (info, success, failure) {
        var url = new URL(window.ClubFlow.ajaxUrl);
        url.searchParams.set('action', window.ClubFlow.actionEvents);
        url.searchParams.set('_ajax_nonce', window.ClubFlow.nonceEvents);
        url.searchParams.set('start', info.startStr);
        url.searchParams.set('end', info.endStr);
        if (category) {
          url.searchParams.set('category', category);
        }

        fetch(url.toString(), { credentials: 'same-origin' })
          .then(function (r) {
            return r.json();
          })
          .then(function (data) {
            if (data && data.success && Array.isArray(data.data)) {
              var events = data.data;
              // Only expand in list views (month grid should remain unchanged)
              try {
                var viewType = calendar && calendar.view && calendar.view.type ? String(calendar.view.type) : '';
                if (viewType.indexOf('list') === 0) {
                  events = expandMultiDayEventsForList(events);
                }
              } catch (e) {}

              success(events);
            } else {
              failure(data && data.data ? data.data : 'Invalid response');
            }
          })
          .catch(function (err) {
            failure(err);
          });
      }
    });

    calendar.render();
  }

  // Toast is handled by PHP (ClubFlow_Booking::maybe_show_confirmation_toast)
  // which is more reliable and works without JS

  // Handle booking forms outside of modal (shortcode embedded)
  function initStandaloneBookingForms() {
    document.addEventListener('submit', function (e) {
      // Skip if inside modal (modal has its own handler)
      if (e.target.closest('.clubflow-modal')) {
        return;
      }
      
      var form = e.target.closest('[data-clubflow-booking-form]');
      if (!form) {
        return;
      }
      
      e.preventDefault();
      handleBookingSubmit(form);
    });

    document.addEventListener('change', function (e) {
      var t = e && e.target ? e.target : null;
      if (!t) return;

      if (t.name === 'is_member') {
        var form = t.closest('[data-clubflow-booking-form]');
        if (!form) return;
        updateFreeFields(form);
      }
    });

    qsa('[data-clubflow-booking-form]').forEach(function (form) {
      updateFreeFields(form);
    });
  }

  // Handle popup booking triggers [club_booking popup="true"]
  function initPopupTriggers() {
    document.addEventListener('click', function (e) {
      var trigger = e.target.closest('[data-clubflow-booking-popup]');
      if (!trigger || trigger.disabled) {
        return;
      }
      
      var eventId = trigger.getAttribute('data-clubflow-booking-popup');
      if (!eventId) {
        return;
      }
      
      // Show loading state
      openModal('<div class="clubflow-modal__loading"><p>Loading...</p></div>');
      
      // Fetch event details via AJAX (same method as calendar eventClick)
      var url = new URL(window.ClubFlow.ajaxUrl);
      url.searchParams.set('action', window.ClubFlow.actionDetails);
      url.searchParams.set('_ajax_nonce', window.ClubFlow.nonceDetails);
      url.searchParams.set('event_id', eventId);
      
      fetch(url.toString(), { credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (data) {
          if (data && data.success && data.data && data.data.html) {
            openModal(data.data.html);
          } else {
            openModal('<p>Could not load booking form.</p>');
          }
        })
        .catch(function () {
          openModal('<p>Could not load booking form.</p>');
        });
    });
  }

  function init() {
    ensureSvLocale();
    initModal();
    initStandaloneBookingForms();
    initPopupTriggers();
    qsa('.clubflow-calendar').forEach(initOne);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
