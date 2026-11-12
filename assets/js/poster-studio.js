/**
 * assets/js/poster-studio.js - Client-Side HTML5 Canvas Poster Engine for LocalNeta
 */

let canvas, ctx;
let userPhotoImg = null;
let dummyUserImg = null;
let defaultPhotoUrl = '';
let dummyUserPhotoUrl = '';
let currentAspectRatio = '1:1'; // '1:1' or '9:16'
let currentTemplate = 'chhath-nahay-khaye';

const TEMPLATE_CONFIGS = {
    'chhath-nahay-khaye': {
        title: 'छठ महापर्व • प्रथम दिवस (नहाय-खाय)',
        headline: 'नहाय-खाय की हार्दिक शुभकामनाएँ',
        bgColors: ['#FF671F', '#B45309', '#78350F'],
        accent: '#FDE047',
        defaultSlogan: "लोक आस्था के चार दिवसीय महापर्व छठ के पावन प्रथम दिवस 'नहाय-खाय' की समस्त क्षेत्रवासियों को हार्दिक शुभकामनाएँ।"
    },
    'chhath-kharna': {
        title: 'छठ महापर्व • द्वितीय दिवस (खरना)',
        headline: 'खरना की हार्दिक शुभकामनाएँ',
        bgColors: ['#DC2626', '#991B1B', '#78350F'],
        accent: '#FEF08A',
        defaultSlogan: "पवित्रता, त्याग और अखंड आस्था के पावन दिवस 'खरना' की आप सभी को अनंत शुभकामनाएँ। छठी मइया सबका कल्याण करें।"
    },
    'chhath-pahla-arg': {
        title: 'छठ महापर्व • तृतीय दिवस (संध्या अर्घ्य)',
        headline: 'पहला अर्घ्य (संध्या अर्घ्य) की शुभकामनाएँ',
        bgColors: ['#EA580C', '#C2410C', '#431407'],
        accent: '#FBBF24',
        defaultSlogan: 'अस्ताचलगामी भगवान सूर्य को प्रथम अर्घ्य के पावन अवसर पर समस्त श्रद्धालुओं को हार्दिक बधाई एवं अनंत शुभकामनाएँ।'
    },
    'chhath-dusra-arg': {
        title: 'छठ महापर्व • चतुर्थ दिवस (उषा अर्घ्य)',
        headline: 'दूसरा अर्घ्य (उषा अर्घ्य) की शुभकामनाएँ',
        bgColors: ['#F97316', '#D97706', '#92400E'],
        accent: '#FEF9C3',
        defaultSlogan: 'उदीयमान भगवान सूर्य को प्रातः अर्घ्य एवं पारण के पावन दिवस पर समस्त व्रतियों एवं क्षेत्रवासियों को सादर नमन एवं शुभकामनाएँ।'
    },
    'chhath': {
        title: 'Chhath Puja Mahaparv',
        headline: 'छठ महापर्व की हार्दिक शुभकामनाएँ',
        bgColors: ['#FF671F', '#B45309', '#78350F'],
        accent: '#FDE047',
        defaultSlogan: 'लोक आस्था के महापर्व छठ पूजा की आप सभी ग्रामवासियों को अनंत शुभकामनाएँ।'
    },
    'diwali': {
        title: 'Shubh Deepawali',
        headline: 'शुभ दीपावली एवं धनतेरस',
        bgColors: ['#0A192F', '#1E3A8A', '#312E81'],
        accent: '#F59E0B',
        defaultSlogan: 'यह दीपोत्सव आपके और आपके परिवार के जीवन में सुख, समृद्धि और शांति लाए।'
    },
    'national': {
        title: 'Republic / Independence Day',
        headline: 'गणतंत्र एवं स्वतंत्रता दिवस की बधाई',
        bgColors: ['#FF671F', '#FFFFFF', '#046A38'],
        accent: '#0A192F',
        defaultSlogan: 'राष्ट्र प्रथम • अखंड भारत • संविधान और लोकतंत्र की रक्षा हमारा परम संकल्प।'
    },
    'holi': {
        title: 'Holi Mahotsav',
        headline: 'रंगोत्सव होली की हार्दिक शुभकामनाएँ',
        bgColors: ['#BE123C', '#9333EA', '#046A38'],
        accent: '#FACC15',
        defaultSlogan: 'आपसी भाईचारे, सौहार्द और खुशहाली के पावन पर्व होली की सप्रेम बधाई।'
    },
    'vikas': {
        title: 'Vikas Project Launch',
        headline: 'विकास कार्य लोकार्पण एवं शिलान्यास',
        bgColors: ['#0A192F', '#046A38', '#024825'],
        accent: '#FF9933',
        defaultSlogan: 'पंचायत के हर वार्ड तक पक्की सड़क, स्वच्छ जल और आधुनिक सुविधा पहुँचाने का संकल्प।'
    },
    'notice': {
        title: 'Gram Sabha Notice',
        headline: 'ग्राम सभा बैठक आम सूचना',
        bgColors: ['#1E293B', '#0F172A', '#0284C7'],
        accent: '#38BDF8',
        defaultSlogan: 'सभी सम्मानित ग्रामवासी बैठक में सादर आमंत्रित हैं। विकास योजनाओं पर खुलकर चर्चा करें।'
    }
};

document.addEventListener('DOMContentLoaded', () => {
    canvas = document.getElementById('posterCanvas');
    if (!canvas) return;
    ctx = canvas.getContext('2d');

    defaultPhotoUrl = canvas.dataset.defaultPhoto || canvas.dataset.dummyPhoto || `${window.LOCALNETA_BASE_URL || ''}/assets/images/dummy-user.png`;
    dummyUserPhotoUrl = canvas.dataset.dummyPhoto || `${window.LOCALNETA_BASE_URL || ''}/assets/images/dummy-user.png`;

    // Preload user's photo or default avatar as initial poster photo
    if (defaultPhotoUrl) {
        const defaultImg = new Image();
        defaultImg.onload = () => {
            userPhotoImg = defaultImg;
            renderPoster();
        };
        defaultImg.src = defaultPhotoUrl;
    }

    renderPoster();

    // Attach input listeners
    ['posterName', 'posterPost', 'posterArea', 'posterPhone', 'posterSlogan'].forEach(id => {
        const el = document.getElementById(id);
        if (el) el.addEventListener('input', renderPoster);
    });
});

function handlePhotoUpload(event) {
    const file = event.target.files[0];
    if (!file) return;

    const reader = new FileReader();
    reader.onload = (e) => {
        const img = new Image();
        img.onload = () => {
            userPhotoImg = img;
            const thumb = document.getElementById('studioPhotoThumb');
            if (thumb) thumb.src = e.target.result;
            const resetBtn = document.getElementById('btnResetDummyPhoto');
            if (resetBtn) resetBtn.style.display = 'inline-flex';
            renderPoster();
        };
        img.src = e.target.result;
    };
    reader.readAsDataURL(file);
}

function resetStudioPhoto() {
    userPhotoImg = null;
    const input = document.getElementById('studioPhotoInput');
    if (input) input.value = '';
    const thumb = document.getElementById('studioPhotoThumb');
    if (thumb && defaultPhotoUrl) thumb.src = defaultPhotoUrl;
    const resetBtn = document.getElementById('btnResetDummyPhoto');
    if (resetBtn) resetBtn.style.display = 'none';

    if (defaultPhotoUrl) {
        const defaultImg = new Image();
        defaultImg.onload = () => {
            userPhotoImg = defaultImg;
            renderPoster();
        };
        defaultImg.src = defaultPhotoUrl;
    } else {
        renderPoster();
    }
}

function setAspectRatio(ratio) {
    currentAspectRatio = ratio;
    document.querySelectorAll('.ratio-btn').forEach(btn => btn.classList.remove('active'));
    const activeBtn = document.getElementById('btn-ratio-' + ratio.replace(':', '-'));
    if (activeBtn) activeBtn.classList.add('active');
    renderPoster();
}

function setTemplate(tplKey) {
    currentTemplate = tplKey;
    document.querySelectorAll('.template-chip').forEach(btn => btn.classList.remove('active'));
    const activeBtn = document.getElementById('tpl-' + tplKey);
    if (activeBtn) activeBtn.classList.add('active');

    const config = TEMPLATE_CONFIGS[tplKey];
    if (config) {
        const sloganInput = document.getElementById('posterSlogan');
        if (sloganInput) {
            sloganInput.value = config.defaultSlogan;
        }
    }
    renderPoster();
}

function renderPoster() {
    if (!canvas || !ctx) return;

    const isSquare = currentAspectRatio === '1:1';
    const W = 1080;
    const H = isSquare ? 1080 : 1920;

    canvas.width = W;
    canvas.height = H;

    const config = TEMPLATE_CONFIGS[currentTemplate] || TEMPLATE_CONFIGS['chhath'];

    const name = (document.getElementById('posterName')?.value || '').trim() || 'Your Leader Name';
    const post = (document.getElementById('posterPost')?.value || '').trim() || 'Representative Designation';
    const area = (document.getElementById('posterArea')?.value || '').trim() || 'Constituency / Gram Panchayat';
    const phone = (document.getElementById('posterPhone')?.value || '').trim();
    const slogan = (document.getElementById('posterSlogan')?.value || '').trim() || config.defaultSlogan;

    // 1. Draw Background Gradient
    const bgGrad = ctx.createLinearGradient(0, 0, W, H);
    if (currentTemplate === 'national') {
        bgGrad.addColorStop(0, '#FF671F');
        bgGrad.addColorStop(0.48, '#FFFFFF');
        bgGrad.addColorStop(0.52, '#FFFFFF');
        bgGrad.addColorStop(1, '#046A38');
    } else {
        bgGrad.addColorStop(0, config.bgColors[0]);
        bgGrad.addColorStop(0.5, config.bgColors[1]);
        bgGrad.addColorStop(1, config.bgColors[2]);
    }
    ctx.fillStyle = bgGrad;
    ctx.fillRect(0, 0, W, H);

    // Decorative Geometric Circles & Vignette
    ctx.save();
    ctx.fillStyle = 'rgba(255, 255, 255, 0.05)';
    ctx.beginPath();
    ctx.arc(W / 2, H * 0.3, W * 0.45, 0, Math.PI * 2);
    ctx.fill();
    ctx.restore();

    // 2. Top Header Brand Bar (LocalNeta.com)
    ctx.fillStyle = 'rgba(10, 25, 47, 0.85)';
    ctx.fillRect(0, 0, W, 90);

    // Top Tricolor stripe
    ctx.fillStyle = '#FF671F';
    ctx.fillRect(0, 0, W / 3, 6);
    ctx.fillStyle = '#FFFFFF';
    ctx.fillRect(W / 3, 0, W / 3, 6);
    ctx.fillStyle = '#046A38';
    ctx.fillRect((W / 3) * 2, 0, W / 3, 6);

    ctx.fillStyle = '#FFFFFF';
    ctx.font = 'bold 36px "Plus Jakarta Sans", sans-serif';
    ctx.textAlign = 'left';
    ctx.fillText('LocalNeta.com', 40, 56);

    ctx.font = '600 24px "Hind", sans-serif';
    ctx.fillStyle = '#FF9933';
    ctx.textAlign = 'right';
    ctx.fillText('आपके नेता, आपके मुद्दे 🇮🇳', W - 40, 56);

    // 3. Central Festival / Announcement Typography
    ctx.textAlign = 'center';
    ctx.fillStyle = config.accent;
    ctx.font = 'bold 32px "Hind", sans-serif';
    ctx.fillText(config.title.toUpperCase(), W / 2, isSquare ? 160 : 220);

    ctx.fillStyle = currentTemplate === 'national' ? '#0A192F' : '#FFFFFF';
    ctx.font = 'bold 64px "Hind", sans-serif';
    ctx.fillText(config.headline, W / 2, isSquare ? 240 : 320);

    // Slogan Box
    ctx.fillStyle = currentTemplate === 'national' ? 'rgba(10, 25, 47, 0.85)' : 'rgba(255, 255, 255, 0.95)';
    ctx.font = '500 28px "Hind", sans-serif';
    wrapText(ctx, `“ ${slogan} ”`, W / 2, isSquare ? 320 : 420, W - 140, 38);

    // 4. Leader Circular Portrait with Gold & Tiranga Rings
    const photoY = isSquare ? 560 : 860;
    const photoRadius = isSquare ? 170 : 220;

    // Outer Glow Ring
    ctx.save();
    ctx.beginPath();
    ctx.arc(W / 2, photoY, photoRadius + 14, 0, Math.PI * 2);
    ctx.fillStyle = '#F59E0B';
    ctx.fill();

    // Tricolor Ring
    ctx.beginPath();
    ctx.arc(W / 2, photoY, photoRadius + 8, 0, Math.PI * 2);
    ctx.fillStyle = '#0A192F';
    ctx.fill();

    // Clip & Draw User Photo
    ctx.beginPath();
    ctx.arc(W / 2, photoY, photoRadius, 0, Math.PI * 2);
    ctx.closePath();
    ctx.clip();

    const activePhoto = userPhotoImg || (dummyUserImg && dummyUserImg.complete && dummyUserImg.naturalWidth > 0 ? dummyUserImg : null);
    if (activePhoto) {
        const srcW = activePhoto.naturalWidth || activePhoto.width;
        const srcH = activePhoto.naturalHeight || activePhoto.height;
        const cropSide = Math.min(srcW, srcH);
        const sx = (srcW - cropSide) / 2;
        const sy = (srcH - cropSide) / 2;
        ctx.drawImage(activePhoto, sx, sy, cropSide, cropSide, (W / 2) - photoRadius, photoY - photoRadius, photoRadius * 2, photoRadius * 2);
    } else {
        ctx.fillStyle = '#E2E8F0';
        ctx.fillRect((W / 2) - photoRadius, photoY - photoRadius, photoRadius * 2, photoRadius * 2);

        // Clean dummy user avatar vector fallback while PNG decodes
        ctx.fillStyle = '#64748B';
        ctx.beginPath();
        ctx.arc(W / 2, photoY - photoRadius * 0.24, photoRadius * 0.30, 0, Math.PI * 2);
        ctx.fill();

        ctx.beginPath();
        ctx.arc(W / 2, photoY + photoRadius * 0.72, photoRadius * 0.58, Math.PI, Math.PI * 2);
        ctx.fill();
    }
    ctx.restore();

    // Verified Badge on Photo
    ctx.save();
    ctx.beginPath();
    ctx.arc((W / 2) + photoRadius * 0.72, photoY + photoRadius * 0.72, 34, 0, Math.PI * 2);
    ctx.fillStyle = '#046A38';
    ctx.fill();
    ctx.lineWidth = 4;
    ctx.strokeStyle = '#FFFFFF';
    ctx.stroke();

    ctx.fillStyle = '#FFFFFF';
    ctx.font = 'bold 24px "Font Awesome 6 Free", sans-serif';
    ctx.textAlign = 'center';
    ctx.fillText('✓', (W / 2) + photoRadius * 0.72, photoY + photoRadius * 0.72 + 8);
    ctx.restore();

    // 5. Leader Information Ribbon at Bottom
    const bottomH = isSquare ? 230 : 340;
    const bottomY = H - bottomH;

    ctx.fillStyle = 'rgba(10, 25, 47, 0.95)';
    ctx.fillRect(0, bottomY, W, bottomH);

    // Accent line
    ctx.fillStyle = '#FF671F';
    ctx.fillRect(0, bottomY, W, 6);

    // Leader Name
    ctx.textAlign = 'center';
    ctx.fillStyle = '#FFFFFF';
    ctx.font = 'bold 50px "Hind", sans-serif';
    ctx.fillText(name, W / 2, bottomY + 70);

    // Leader Post / Designation
    ctx.fillStyle = '#38BDF8';
    ctx.font = 'bold 34px "Hind", sans-serif';
    ctx.fillText(post, W / 2, bottomY + 120);

    // Area & Panchayat
    ctx.fillStyle = '#CBD5E1';
    ctx.font = '500 28px "Hind", sans-serif';
    ctx.fillText(area, W / 2, bottomY + 165);

    // Phone / WhatsApp contact badge
    if (phone) {
        ctx.fillStyle = '#22C55E';
        ctx.font = '600 24px "Plus Jakarta Sans", sans-serif';
        ctx.fillText(`WhatsApp / Call: ${phone}`, W / 2, bottomY + 205);
    }
}

// Helper to wrap text cleanly on canvas
function wrapText(context, text, x, y, maxWidth, lineHeight) {
    const words = text.split(' ');
    let line = '';

    for (let n = 0; n < words.length; n++) {
        const testLine = line + words[n] + ' ';
        const metrics = context.measureText(testLine);
        const testWidth = metrics.width;
        if (testWidth > maxWidth && n > 0) {
            context.fillText(line, x, y);
            line = words[n] + ' ';
            y += lineHeight;
        } else {
            line = testLine;
        }
    }
    context.fillText(line, x, y);
}

// Download Canvas as HD PNG
function downloadPoster() {
    if (!canvas) return;
    const link = document.createElement('a');
    const name = document.getElementById('posterName')?.value || 'Leader';
    link.download = `LocalNeta_${name.replace(/\s+/g, '_')}_Poster.png`;
    link.href = canvas.toDataURL('image/png', 1.0);
    link.click();
    showToast('✅ High-Resolution Poster Downloaded!');
}

// Share to WhatsApp
function sharePosterWhatsApp() {
    const name = document.getElementById('posterName')?.value || 'Representative';
    const text = encodeURIComponent(`Namaste! Check out this greeting poster created with LocalNeta Studio for ${name}. Visit: https://localneta.com`);
    window.open(`https://wa.me/?text=${text}`, '_blank');
}

