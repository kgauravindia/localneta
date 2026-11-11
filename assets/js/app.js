/**
 * assets/js/app.js - Main Application Logic for LocalNeta (Powered by op_sdb)
 */

// Modal Handlers
function openSearchModal() {
    const modal = document.getElementById('searchModal');
    if (modal) {
        modal.classList.add('active');
        const input = document.getElementById('quickSearchInput');
        if (input) setTimeout(() => input.focus(), 150);
    }
}

function closeSearchModal() {
    const modal = document.getElementById('searchModal');
    if (modal) modal.classList.remove('active');
}

// Close modals when clicking backdrop
window.addEventListener('click', function(e) {
    if (e.target.classList.contains('modal-overlay')) {
        e.target.classList.remove('active');
    }
});

// Toast Display
function showToast(message, duration = 3000) {
    const toast = document.getElementById('globalToast');
    const msgEl = document.getElementById('toastMessage');
    if (!toast || !msgEl) return;

    msgEl.textContent = message;
    toast.classList.add('show');

    setTimeout(() => {
        toast.classList.remove('show');
    }, duration);
}

// Interactive Poll Voting
function votePoll(pollId, optionIndex) {
    const pollBox = document.getElementById(`poll-box-${pollId}`);
    if (!pollBox) return;

    fetch(`${window.LOCALNETA_BASE_URL}/api/polls`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ poll_id: pollId, option_index: optionIndex })
    })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            showToast('✅ Your vote has been recorded!');
            data.options.forEach((opt, idx) => {
                const btn = pollBox.querySelector(`.poll-opt-${idx + 1}`);
                if (btn) {
                    const progressBar = btn.querySelector('.poll-progress-bar');
                    const pctEl = btn.querySelector('.poll-pct');
                    if (progressBar) progressBar.style.width = `${opt.percentage}%`;
                    if (pctEl) pctEl.textContent = `${opt.percentage}% (${opt.votes} votes)`;
                }
            });
            const totalEl = pollBox.querySelector('.poll-total-votes');
            if (totalEl) totalEl.textContent = `Total votes: ${data.total_votes}`;
        } else {
            showToast(data.message || 'Could not record vote');
        }
    })
    .catch(() => {
        showToast('✅ Your vote was recorded!');
    });
}

// Upvote Issue
function upvoteIssue(issueId, btn) {
    fetch(`${window.LOCALNETA_BASE_URL}/api/issues?action=upvote`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ issue_id: issueId })
    })
    .then(res => res.json())
    .then(data => {
        if (btn) {
            btn.innerHTML = `<i class="fas fa-thumbs-up" style="color:var(--saffron-main);"></i> Supported (${data.new_count || '1'})`;
            btn.classList.add('active');
        }
        showToast('Your support has been registered!');
    })
    .catch(() => {
        if (btn) {
            btn.innerHTML = `<i class="fas fa-thumbs-up" style="color:var(--saffron-main);"></i> Supported`;
        }
        showToast('Support registered!');
    });
}

// Quick Search Live Filter
function handleQuickSearch(query) {
    const resultsBox = document.getElementById('searchResultsBox');
    if (!resultsBox) return;

    if (!query || query.trim().length < 2) {
        resultsBox.innerHTML = '<p style="color:var(--text-muted); font-size:0.85rem; text-align:center; padding:20px;">Type a name or keyword...</p>';
        return;
    }

    fetch(`${window.LOCALNETA_BASE_URL}/api/search?q=${encodeURIComponent(query)}`)
        .then(res => res.json())
        .then(data => {
            if (!data.results || data.results.length === 0) {
                resultsBox.innerHTML = '<p style="color:var(--text-muted); font-size:0.88rem; text-align:center; padding:20px;">No matching results found.</p>';
                return;
            }

            let html = '<div style="display:flex; flex-direction:column; gap:8px;">';
            data.results.forEach(item => {
                html += `
                    <a href="${item.url}" style="padding:10px; border-radius:8px; border:1px solid var(--border-color); display:flex; align-items:center; gap:10px; background:#fff;">
                        <div style="width:36px; height:36px; border-radius:50%; background:var(--navy-light); display:flex; align-items:center; justify-content:center; color:var(--saffron-main); font-size:0.9rem;">
                            <i class="${item.icon}"></i>
                        </div>
                        <div style="flex:1;">
                            <div style="font-weight:700; font-size:0.92rem; color:var(--navy-main);">${item.title}</div>
                            <div style="font-size:0.75rem; color:var(--text-muted);">${item.subtitle}</div>
                        </div>
                        <i class="fas fa-chevron-right" style="color:var(--text-muted); font-size:0.8rem;"></i>
                    </a>
                `;
            });
            html += '</div>';
            resultsBox.innerHTML = html;
        })
        .catch(err => console.error(err));
}

// Header User Dropdown Menu
function toggleHeaderUserMenu(e) {
    if (e) e.stopPropagation();
    const menu = document.getElementById('headerUserMenu');
    const trigger = e && e.currentTarget ? e.currentTarget : null;
    if (!menu) return;
    const isHidden = menu.hasAttribute('hidden');
    if (isHidden) {
        menu.removeAttribute('hidden');
        if (trigger) trigger.setAttribute('aria-expanded', 'true');
    } else {
        menu.setAttribute('hidden', '');
        if (trigger) trigger.setAttribute('aria-expanded', 'false');
    }
}

document.addEventListener('click', function(e) {
    const dropdown = document.getElementById('headerUserDropdown');
    const menu = document.getElementById('headerUserMenu');
    if (dropdown && menu && !dropdown.contains(e.target)) {
        menu.setAttribute('hidden', '');
        const btn = dropdown.querySelector('.user-nav-trigger');
        if (btn) btn.setAttribute('aria-expanded', 'false');
    }
});

// Login & Quota Modals for Contact Reveal
function openAuthRequiredModal(targetName) {
    const modal = document.getElementById('authRequiredModal');
    if (!modal) return;
    const desc = document.getElementById('authRequiredModalDesc');
    if (desc) {
        desc.textContent = targetName
            ? `Sign in or register on LocalNeta to view the unmasked mobile number and email ID of ${targetName}.`
            : 'To prevent spam and automated harvesting, unmasked mobile numbers and email IDs are available only to signed-in citizens and politicians.';
    }
    const baseUrl = window.LOCALNETA_BASE_URL || '';
    const redir = encodeURIComponent(window.location.pathname + window.location.search + window.location.hash);
    const loginLink = document.getElementById('authModalLoginLink');
    const regLink = document.getElementById('authModalRegisterLink');
    if (loginLink) loginLink.href = `${baseUrl}/login?redirect=${redir}`;
    if (regLink) regLink.href = `${baseUrl}/register?redirect=${redir}`;
    modal.classList.add('active');
}

function closeAuthRequiredModal() {
    const modal = document.getElementById('authRequiredModal');
    if (modal) modal.classList.remove('active');
}

function openDailyLimitModal(msg) {
    const modal = document.getElementById('dailyLimitModal');
    if (!modal) return;
    const desc = document.getElementById('dailyLimitModalDesc');
    if (desc && msg) desc.textContent = msg;
    modal.classList.add('active');
}

function closeDailyLimitModal() {
    const modal = document.getElementById('dailyLimitModal');
    if (modal) modal.classList.remove('active');
}

function renderRevealedContactSlot(wrapEl, fieldName, rawValue) {
    if (!wrapEl || !rawValue) return;
    const unlockedBox = wrapEl.querySelector('.contact-unlocked-value');
    const maskedRow = wrapEl.querySelector('.contact-reveal-masked-row') || wrapEl.querySelector('.contact-reveal-trigger');
    if (!unlockedBox) return;

    unlockedBox.innerHTML = '';
    const isEmailField = fieldName === 'email';
    const parts = String(rawValue).split(isEmailField ? /[,;]+/ : /[,;/|]+/).map(s => s.trim()).filter(Boolean);

    parts.forEach(item => {
        if (isEmailField) {
            const mailA = document.createElement('a');
            mailA.href = 'mailto:' + item;
            mailA.className = 'contact-unlocked-link';
            const icon = document.createElement('i');
            icon.className = 'fas fa-envelope';
            mailA.appendChild(icon);
            mailA.appendChild(document.createTextNode(' ' + item));
            unlockedBox.appendChild(mailA);
        } else {
            const digits = item.replace(/\D+/g, '');
            const telDigits = digits.length === 10 ? ('+91' + digits) : (digits.length === 12 && digits.startsWith('91') ? ('+' + digits) : digits);
            const telA = document.createElement('a');
            telA.href = 'tel:' + telDigits;
            telA.className = 'contact-unlocked-link';
            const icon = document.createElement('i');
            icon.className = fieldName === 'whatsapp' ? 'fab fa-whatsapp' : 'fas fa-phone';
            telA.appendChild(icon);
            telA.appendChild(document.createTextNode(' ' + item));
            unlockedBox.appendChild(telA);

            if ((digits.length === 10 && /^[6-9]/.test(digits)) || (digits.length === 12 && digits.startsWith('91'))) {
                const waDigits = digits.length === 10 ? ('91' + digits) : digits;
                const waA = document.createElement('a');
                waA.href = 'https://wa.me/' + waDigits;
                waA.target = '_blank';
                waA.rel = 'noopener';
                waA.className = 'contact-wa-chip';
                const waIcon = document.createElement('i');
                waIcon.className = 'fab fa-whatsapp';
                waA.appendChild(waIcon);
                waA.appendChild(document.createTextNode(' WhatsApp'));
                unlockedBox.appendChild(waA);
            }
        }
    });

    const copyBtn = document.createElement('button');
    copyBtn.type = 'button';
    copyBtn.className = 'contact-copy-btn';
    copyBtn.innerHTML = '<i class="fas fa-copy"></i> Copy';
    copyBtn.addEventListener('click', function() {
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(rawValue).then(() => {
                showToast('Copied: ' + rawValue);
            }).catch(() => {});
        } else {
            showToast(rawValue);
        }
    });
    unlockedBox.appendChild(copyBtn);

    if (maskedRow) maskedRow.setAttribute('hidden', '');
    unlockedBox.removeAttribute('hidden');
}

function handleContactRevealClick(btn) {
    const wrap = btn ? btn.closest('.contact-reveal-wrap') : null;
    if (!wrap) return;

    const entityType = wrap.dataset.entityType || '';
    const entityId = parseInt(wrap.dataset.entityId || '0', 10);
    const field = wrap.dataset.field || 'mobile';
    const targetName = wrap.dataset.targetName || '';

    if (!window.LOCALNETA_LOGGED_IN || wrap.dataset.authRequired === '1') {
        openAuthRequiredModal(targetName);
        return;
    }

    if (!entityType || !entityId || btn.dataset.loading === '1') return;

    const originalHtml = btn.innerHTML;
    btn.dataset.loading = '1';
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Unlocking...';

    const baseUrl = window.LOCALNETA_BASE_URL || '';
    fetch(`${baseUrl}/api/reveal-contact`, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-Token': window.LOCALNETA_CSRF || ''
        },
        body: JSON.stringify({
            entity_type: entityType,
            entity_id: entityId,
            field: field,
            csrf_token: window.LOCALNETA_CSRF || ''
        })
    })
    .then(res => res.json())
    .then(data => {
        btn.dataset.loading = '0';
        btn.disabled = false;
        btn.innerHTML = originalHtml;

        if (data.require_login) {
            openAuthRequiredModal(targetName);
            return;
        }
        if (data.limit_reached) {
            openDailyLimitModal(data.message);
            return;
        }
        if (!data.success) {
            showToast(data.message || 'Unable to reveal contact right now.');
            return;
        }

        // Reveal this slot AND any sibling masked slots for the same entity (e.g., mobile + alt mobile + email on one profile)
        const contacts = data.contacts || {};
        const matchingSlots = document.querySelectorAll(`.contact-reveal-wrap[data-entity-type="${entityType}"][data-entity-id="${entityId}"]`);
        matchingSlots.forEach(slotEl => {
            const slotField = slotEl.dataset.field || 'mobile';
            const slotVal = contacts[slotField] || (slotEl === wrap ? data.value : '');
            if (slotVal) {
                renderRevealedContactSlot(slotEl, slotField, slotVal);
            }
        });

        const remMsg = typeof data.reveals_remaining === 'number'
            ? `Unlocked! Remaining today: ${data.reveals_remaining}/${data.max_daily || 10}`
            : 'Contact unlocked!';
        showToast(remMsg);
    })
    .catch(() => {
        btn.dataset.loading = '0';
        btn.disabled = false;
        btn.innerHTML = originalHtml;
        showToast('Network error while unlocking contact. Please try again.');
    });
}

