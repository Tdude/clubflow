(function () {
  function qs(sel, root) {
    return (root || document).querySelector(sel);
  }

  function setCookie(name, value, days) {
    try {
      var expires = '';
      if (typeof days === 'number') {
        var d = new Date();
        d.setTime(d.getTime() + days * 24 * 60 * 60 * 1000);
        expires = '; expires=' + d.toUTCString();
      }
      document.cookie = name + '=' + encodeURIComponent(value || '') + expires + '; path=/; samesite=lax';
    } catch (e) {
      // Ignore cookie failures
    }
  }

  function getCookie(name) {
    try {
      var needle = name + '=';
      var parts = (document.cookie || '').split(';');
      for (var i = 0; i < parts.length; i++) {
        var c = parts[i].trim();
        if (c.indexOf(needle) === 0) {
          return decodeURIComponent(c.substring(needle.length));
        }
      }
    } catch (e) {
      // Ignore cookie failures
    }
    return '';
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

    // Disable hovercard on touch devices — it overlaps the modal on tap
    if ('ontouchstart' in window || navigator.maxTouchPoints > 0) {
      return;
    }

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
        var restText = (info.timeText || '').trim();
        var startTimeText = '';
        try {
          if (info.event && info.event.start && !info.event.allDay) {
            startTimeText = new Intl.DateTimeFormat('sv-SE', {
              hour: '2-digit',
              minute: '2-digit'
            }).format(info.event.start);
          }
        } catch (e) {}

        if (!restText && startTimeText) {
          restText = startTimeText;
        }
        timeCell.textContent = '';

		var dateStr = startStr.split('T')[0];
		var dateLine = document.createElement('div');
		dateLine.style.fontWeight = '600';
		dateLine.textContent = dateStr;
		timeCell.appendChild(dateLine);

		if (restText) {
			var timeLine = document.createElement('div');
			timeLine.textContent = restText;
			timeCell.appendChild(timeLine);
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

    // Remove any hovercard so it doesn't sit on top of the modal
    removeHoverCard(true);

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

      // Progressive guidance messages based on elapsed time
      var elapsedSeconds = pollCount * 5;
      var textEl = qs('.clubflow-status-text', containerEl);
      if (textEl) {
        if (elapsedSeconds >= 300) {
          textEl.textContent = 'Väntar fortfarande... Om betalningen inte fungerar, kontakta oss.';
        } else if (elapsedSeconds >= 120) {
          textEl.textContent = 'Betalningen verkar ta lång tid. Kontrollera att beloppet stämmer i Swish-appen.';
        } else if (elapsedSeconds >= 30) {
          textEl.textContent = 'Har du skannat QR-koden? Öppna Swish-appen och godkänn betalningen.';
        }
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

  function clearFieldError(fieldWrap) {
    if (!fieldWrap) return;
    fieldWrap.classList.remove('clubflow-field-error');
    var msg = qs('.clubflow-field-error-msg', fieldWrap);
    if (msg && msg.parentNode) {
      msg.parentNode.removeChild(msg);
    }
  }

  function setFieldError(fieldWrap, message) {
    if (!fieldWrap) return;
    clearFieldError(fieldWrap);
    fieldWrap.classList.add('clubflow-field-error');
    var span = document.createElement('span');
    span.className = 'clubflow-field-error-msg';
    span.textContent = message;
    fieldWrap.appendChild(span);
  }

  function validateBookingForm(form) {
    var valid = true;

    // Clear all previous field errors
    qsa('.clubflow-field-error', form).forEach(function (el) {
      clearFieldError(el);
    });

    // Name
    var nameInput = qs('input[name="name"]', form);
    if (nameInput) {
      var nameVal = (nameInput.value || '').trim();
      if (!nameVal) {
        setFieldError(nameInput.closest('.clubflow-booking-widget__field'), (window.ClubFlow && window.ClubFlow.i18n && window.ClubFlow.i18n.nameRequired) || 'Namn kr\u00e4vs');
        valid = false;
      }
    }

    // Email
    var emailInput = qs('input[name="email"]', form);
    if (emailInput) {
      var emailVal = (emailInput.value || '').trim();
      var emailPattern = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
      if (!emailVal || !emailPattern.test(emailVal)) {
        setFieldError(emailInput.closest('.clubflow-booking-widget__field'), (window.ClubFlow && window.ClubFlow.i18n && window.ClubFlow.i18n.invalidEmail) || 'Ogiltig e-postadress');
        valid = false;
      }
    }

    // Phone
    var phoneInput = qs('input[name="phone"]', form);
    if (phoneInput) {
      var phoneVal = (phoneInput.value || '').trim();
      if (!phoneVal) {
        setFieldError(phoneInput.closest('.clubflow-booking-widget__field'), (window.ClubFlow && window.ClubFlow.i18n && window.ClubFlow.i18n.phoneRequired) || 'Telefonnummer kr\u00e4vs');
        valid = false;
      }
    }

    // Address fields (only when visible / required)
    qsa('[data-clubflow-free-field]', form).forEach(function (wrap) {
      if (wrap.style.display === 'none') return;
      var input = qs('input', wrap);
      if (input && input.hasAttribute('required') && !(input.value || '').trim()) {
        var label = qs('label', wrap);
        var fieldName = label ? label.textContent.replace(/\s*\*\s*$/, '').trim() : ((window.ClubFlow && window.ClubFlow.i18n && window.ClubFlow.i18n.fieldFallback) || 'F\u00e4lt');
        setFieldError(wrap, fieldName + ' ' + ((window.ClubFlow && window.ClubFlow.i18n && window.ClubFlow.i18n.fieldRequired) || 'kr\u00e4vs'));
        valid = false;
      }
    });

    return valid;
  }

  function handleBookingSubmit(form) {
    if (!window.ClubFlow) {
      return;
    }

    updateFreeFields(form);

    // Client-side validation
    if (!validateBookingForm(form)) {
      return;
    }

    var submitBtn = qs('button[type="submit"]', form);
    var messageEl = qs('[data-clubflow-booking-message]', form.parentNode);
    
    if (submitBtn) {
      submitBtn.disabled = true;
      submitBtn.textContent = window.ClubFlow.i18n.booking || 'Bokar...';
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
            
            var confCode = data.data.confirmation_code || '';
            var html = '<strong>' + (window.ClubFlow.i18n.booked || 'Bokad!') + '</strong><br>' +
              (window.ClubFlow.i18n.bookingSuccess || 'Din bokning är bekräftad! Bekräftelsekod:') +
              ' <strong>' + confCode + '</strong>' +
              (confCode ? ' <button type="button" class="clubflow-copy-btn" data-clubflow-copy="' + confCode + '">' + (window.ClubFlow.i18n.copy || 'Kopiera') + '</button>' : '');

            if (data.data && data.data.klippkort_code) {
              html += '<br>' + (window.ClubFlow.i18n.klippkortCode || 'Klippkortkod:') +
                ' <strong>' + data.data.klippkort_code + '</strong>';

              // Store as a convenience hint for later bookings on this device
              setCookie('clubflow_klippkort_code', data.data.klippkort_code, 180);
            }

            if (data.data && (data.data.discount_code || data.data.discount_percent)) {
              var dp = data.data.discount_percent || '';
              var dc = data.data.discount_code || '';
              var discText = '';
              if (dp) {
                discText += dp + '%';
              }
              if (dc) {
                discText += (discText ? ' ' : '') + dc;
              }
              html += '<br>' + (window.ClubFlow.i18n.discount || 'Rabatt:') +
                ' <strong>' + discText + '</strong>';
            }
            
            // Show payment info if present
            if (data.data.payment) {
              var p = data.data.payment;
              html += '<div class="clubflow-payment-info" data-payment-reference="' + (p.reference || '') + '" data-check-url="' + (p.check_url || '') + '">';
              
              if (p.method === 'stripe' && p.checkout_url) {
                // Stripe Checkout - redirect
                html += '<div class="clubflow-stripe-checkout">';
                html += '<p>' + (window.ClubFlow.i18n.redirectingToPayment || 'Skickar dig till betalning...') + '</p>';
                html += '<a href="' + p.checkout_url + '" class="clubflow-stripe-button">' +
                  (window.ClubFlow.i18n.proceedToPayment || 'Gå till betalning') + ' →</a>';
                html += '</div>';
                
                // Auto-redirect after short delay
                // Use a flag so the generic redirect_url handler below skips
                var stripeRedirectUrl = p.checkout_url;
                setTimeout(function() {
                  window.location.href = stripeRedirectUrl;
                }, 500);
                
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
            
            // When Klarna checkout is present, expand modal and clear previous content
            var modal = getModal();
            if (data.data.payment && data.data.payment.method === 'klarna') {
              if (modal) modal.classList.add('clubflow-modal--klarna');
              messageEl.innerHTML = '';
            } else {
              if (modal) modal.classList.remove('clubflow-modal--klarna');
            }

            messageEl.innerHTML = html;
            messageEl.style.display = 'block';

            // Copy-to-clipboard for confirmation code
            var copyBtn = messageEl.querySelector('.clubflow-copy-btn');
            if (copyBtn) {
              copyBtn.addEventListener('click', function() {
                var code = this.getAttribute('data-clubflow-copy');
                var btn = this;
                navigator.clipboard.writeText(code).then(function() {
                  btn.textContent = (window.ClubFlow && window.ClubFlow.i18n && window.ClubFlow.i18n.copied) || 'Kopierad!';
                  setTimeout(function() { btn.textContent = (window.ClubFlow && window.ClubFlow.i18n && window.ClubFlow.i18n.copy) || 'Kopiera'; }, 2000);
                });
              });
            }

            // If server provided a redirect URL (e.g. free bookings), navigate there
            // so the PHP confirmation toast can show.
            // Skip if Stripe/Klarna payment is pending — those have their own redirect.
            if (data.data && data.data.redirect_url &&
                !(data.data.payment && (data.data.payment.method === 'stripe' || data.data.payment.method === 'klarna'))) {
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
            submitBtn.textContent = window.ClubFlow.i18n.bookNow || 'Boka nu';
          }
          if (messageEl) {
            messageEl.className = 'clubflow-booking__message clubflow-booking__message--error';
            var errMsg = (data && data.data) ? data.data : (window.ClubFlow.i18n.bookingError || 'Bokningen misslyckades.');
            messageEl.textContent = errMsg;
            messageEl.style.display = 'block';

            // If user already booked, show a helpful klippkort hint if we have a saved code cookie.
            // This is informational only.
            if (typeof errMsg === 'string' && errMsg.indexOf('Du har redan bokat detta event') !== -1) {
              var savedCode = getCookie('clubflow_klippkort_code');
              var emailInput = qs('input[name="email"]', form);
              var emailVal = emailInput ? (emailInput.value || '') : '';

              if (savedCode && emailVal && window.ClubFlow && window.ClubFlow.actionKlippkortRemaining && window.ClubFlow.nonceKlippkortRemaining) {
                var fd = new FormData();
                fd.append('action', window.ClubFlow.actionKlippkortRemaining);
                fd.append('_ajax_nonce', window.ClubFlow.nonceKlippkortRemaining);
                fd.append('email', emailVal);
                fd.append('klippkort_code', savedCode);

                fetch(window.ClubFlow.ajaxUrl, {
                  method: 'POST',
                  credentials: 'same-origin',
                  body: fd
                })
                  .then(function (r) { return r.json(); })
                  .then(function (resp) {
                    if (!resp || !resp.success || !resp.data) return;
                    if (resp.data.remaining === null || typeof resp.data.remaining === 'undefined') return;
                    var n = parseInt(resp.data.remaining, 10);
                    if (isNaN(n)) return;
                    var hintTpl = (window.ClubFlow && window.ClubFlow.i18n && window.ClubFlow.i18n.klippkortHint) || 'Du verkar ha %d klipp kvar.';
                    messageEl.textContent = errMsg + ' ' + hintTpl.replace('%d', n);
                  })
                  .catch(function () {
                    // Ignore hint failures
                  });
              }
            }
          }
        }
      })
      .catch(function () {
        if (submitBtn) {
          submitBtn.disabled = false;
          submitBtn.textContent = window.ClubFlow.i18n.bookNow || 'Boka nu';
        }
        if (messageEl) {
          messageEl.className = 'clubflow-booking__message clubflow-booking__message--error';
          messageEl.textContent = window.ClubFlow.i18n.bookingError || 'Bokningen misslyckades.';
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

    // Show a non-blocking notice when the events feed fails, instead of leaving
    // the calendar silently empty (which looks identical to "no events").
    function showFeedError() {
      var existing = el.parentNode ? el.parentNode.querySelector('[data-clubflow-feed-error]') : null;
      if (existing) {
        existing.style.display = '';
        return;
      }
      var note = document.createElement('div');
      note.setAttribute('data-clubflow-feed-error', '1');
      note.className = 'clubflow-feed-error';
      note.setAttribute('role', 'status');
      note.textContent = (window.ClubFlow && window.ClubFlow.i18n && window.ClubFlow.i18n.feedError)
        || 'Kunde inte ladda kalendern just nu. Försök att ladda om sidan.';
      if (el.parentNode) {
        el.parentNode.insertBefore(note, el);
      }
    }
    function clearFeedError() {
      var existing = el.parentNode ? el.parentNode.querySelector('[data-clubflow-feed-error]') : null;
      if (existing) {
        existing.style.display = 'none';
      }
    }

    var initialView = el.getAttribute('data-view') || 'dayGridMonth';
    var initialDate = el.getAttribute('data-initial-date') || '';
    var titleElId = el.getAttribute('data-title-id') || '';
    var externalTitleEl = titleElId ? document.getElementById(titleElId) : null;
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
    var mobileToolbar = externalTitleEl
      ? { left: 'prev,next', center: '', right: 'dayGridMonth,listTwoWeeks today' }
      : { left: 'prev,next', center: 'title', right: 'dayGridMonth,listTwoWeeks today' };
    var desktopToolbar = externalTitleEl
      ? { left: 'prev,next today', center: '', right: 'dayGridMonth,listRange' }
      : { left: 'prev,next today', center: 'title', right: 'dayGridMonth,listRange' };
    var calendar = new FullCalendar.Calendar(el, {
      headerToolbar: isMobile ? mobileToolbar : desktopToolbar,
      titleFormat: isMobile ? { month: 'long' } : { year: 'numeric', month: 'long' },
      timeZone: (window.ClubFlow && window.ClubFlow.timeZone) ? window.ClubFlow.timeZone : 'local',
      locale: 'sv',
      firstDay: 1,
      displayEventTime: true,
      displayEventEnd: false,
      eventDataTransform: function (eventData) {
        try {
          var showBlocksMonth = true;
          try {
            if (window.ClubFlow && typeof window.ClubFlow.showBlocksMonth !== 'undefined') {
              showBlocksMonth = !!window.ClubFlow.showBlocksMonth;
            }
          } catch (e) {}

          // If disabled, always render as dots in month grid.
          if (!showBlocksMonth) {
            eventData = eventData || {};
            eventData.display = 'list-item';
            return eventData;
          }

          // When enabled:
          // - start only => dot
          // - start + end => full block card
          var hasEnd = !!(eventData && eventData.end);
          eventData = eventData || {};
          eventData.display = hasEnd ? 'block' : 'list-item';
          return eventData;
        } catch (e) {
          return eventData;
        }
      },
      views: {
        listRange: {
          type: 'list',
          duration: listDuration,
          buttonText: listButtonText
        },
        listTwoWeeks: {
          type: 'list',
          duration: { weeks: 2 },
          buttonText: 'Lista'
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
        // Sync external title heading with current month
        if (externalTitleEl && calendar) {
          try {
            var currentDate = calendar.getDate();
            var monthName = currentDate.toLocaleString('sv-SE', { month: 'long', year: 'numeric' });
            monthName = monthName.charAt(0).toUpperCase() + monthName.slice(1);
            externalTitleEl.innerHTML = '<span class="upaif-title__first">Kalender</span> – ' + monthName;
          } catch (e) {}
        }
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

        openModal('<div class="clubflow-modal-loading"><span class="clubflow-spinner"></span></div>');

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

              clearFeedError();
              success(events);
            } else {
              // e.g. nonce/permission failure returns -1, or a server-side error.
              showFeedError();
              failure(data && data.data ? data.data : 'Invalid response');
            }
          })
          .catch(function (err) {
            showFeedError();
            failure(err);
          });
      }
    });

    calendar.render();
  }

  // Toast is handled by PHP (ClubFlow_Booking::maybe_show_confirmation_toast)
  // which is more reliable and works without JS

  // Clear field-level validation errors as the user types
  function initFieldErrorClearing() {
    document.addEventListener('input', function (e) {
      var t = e && e.target ? e.target : null;
      if (!t) return;
      var fieldWrap = t.closest('.clubflow-booking-widget__field');
      if (fieldWrap && fieldWrap.classList.contains('clubflow-field-error')) {
        clearFieldError(fieldWrap);
      }
    });

    document.addEventListener('change', function (e) {
      var t = e && e.target ? e.target : null;
      if (!t) return;
      var fieldWrap = t.closest('.clubflow-booking-widget__field');
      if (fieldWrap && fieldWrap.classList.contains('clubflow-field-error')) {
        clearFieldError(fieldWrap);
      }
    });
  }

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

		if (t && t.hasAttribute && t.hasAttribute('data-clubflow-payment-method')) {
			var form = t.closest('[data-clubflow-booking-form]');
			if (!form) return;
			updateKlippkortFields(form);
		}
    });

    qsa('[data-clubflow-booking-form]').forEach(function (form) {
      updateFreeFields(form);
		updateKlippkortFields(form);
    });
  }

	function updateKlippkortFields(form) {
		if (!form) return;
		var codeField = qs('[data-clubflow-klippkort-code]', form);
		if (!codeField) return;
		var selected = qs('input[name="payment_method"]:checked', form);
		var isKlippkort = selected && selected.value === 'klippkort';
		codeField.style.display = isKlippkort ? '' : 'none';

		if (isKlippkort) {
			var input = qs('input[name="klippkort_code"]', codeField);
			if (input && !input.value) {
				var saved = getCookie('clubflow_klippkort_code');
				if (saved) {
					input.value = saved;
				}
			}
		}
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
      openModal('<div class="clubflow-modal-loading"><span class="clubflow-spinner"></span></div>');
      
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
    initFieldErrorClearing();
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
