/**
 * assets/js/content-generator.js - Smart Slogan & Social Media Content Generator
 */

const PRESET_COPY = {
    'chhath': {
        slogans: [
            "लोक आस्था, पवित्रता और अखंड विश्वास का महापर्व — छठ पूजा की हार्दिक शुभकामनाएँ।",
            "भगवान भास्कर और छठी मइया की कृपा से आपकी पंचायत में सुख, शांति और समृद्धि आए।",
            "स्वच्छ घाट, निर्मल जल और विकसित पंचायत — यही हमारा संकल्प।"
        ],
        whatsapp: "🙏 समस्त ग्रामवासियों एवं क्षेत्रवासियों को लोक आस्था के महापर्व छठ पूजा की हार्दिक शुभकामनाएँ। भगवान सूर्य एवं छठी मइया आप सभी के जीवन में उत्तम स्वास्थ्य, खुशहाली एवं निरंतर प्रगति प्रदान करें।\n\n— विनीत: [NAME]\n[POST], [AREA]\nLocalNeta.com Verified",
        facebook: "आस्था, नियम और निष्ठा का प्रतीक महापर्व छठ पूजा की समस्त बिहारवासियों को हार्दिक बधाई एवं अनंत शुभकामनाएँ। आइए, इस पावन अवसर पर अपनी पंचायत को स्वच्छ, स्वस्थ और विकसित बनाने का संकल्प लें। #ChhathPuja #LocalNeta #PanchayatVikas"
    },
    'diwali': {
        slogans: [
            "दीप जले, दिल मिले — खुशियों से हर घर खिले। शुभ दीपावली!",
            "स्वच्छ गाँव, रोशन पंचायत, उज्ज्वल भविष्य।",
            "अंधकार पर प्रकाश की विजय का पावन पर्व आपके जीवन में खुशहाली लाए।"
        ],
        whatsapp: "✨ दीपों के पावन पर्व दीपावली एवं धनतेरस की आप सभी सम्मानित ग्रामवासियों को हार्दिक शुभकामनाएँ। महालक्ष्मी और भगवान गणेश की कृपा आप सभी पर सदैव बनी रहे।\n\n— विनीत: [NAME]\n[POST], [AREA]",
        facebook: "प्रकाश, उमंग और खुशियों के महापर्व दीपावली की समस्त क्षेत्रवासियों को हार्दिक बधाई। आइए इस दीपोत्सव अपनी पंचायत में प्रेम, सौहार्द और विकास की रोशनी फैलाएँ। #HappyDiwali #LocalNeta"
    },
    'vikas': {
        slogans: [
            "विकास की बात, जनता के साथ — पंचायत का मान बढ़ाएंगे।",
            "सड़क, नाली, नल का जल — बेहतर बनाएंगे सबका कल।",
            "काम किया है, काम करेंगे — हर वार्ड को सशक्त करेंगे।"
        ],
        whatsapp: "📢 ग्राम पंचायत विकास सूचना!\n\nवार्ड संख्या में पक्की सड़क एवं भूमिगत नाली निर्माण कार्य का विधिवत शिलान्यास किया गया। कार्य की गुणवत्ता और पारदर्शिता बनाए रखने के लिए सभी ग्रामवासी सादर आमंत्रित हैं।\n\n— [NAME], [POST]\n[AREA]\nLocalNeta.com/profile",
        facebook: "पंचायत के सर्वांगीण विकास की दिशा में एक और महत्वपूर्ण कदम! आज वार्ड में विकास कार्य का शुभारंभ हुआ। हमारा एकमात्र लक्ष्य है कि पंचायत के प्रत्येक नागरिक तक मूलभूत सुविधाएँ समय पर पहुँचें। #GramPanchayat #VikasTracker #LocalNeta"
    },
    'notice': {
        slogans: [
            "जनता की राय, विकास की दिशा — ग्राम सभा में जरूर आएं।",
            "सशक्त ग्राम सभा, पारदर्शी पंचायत।",
            "गाँव का विकास, सबका विश्वास।"
        ],
        whatsapp: "📌 अति महत्वपूर्ण आम सूचना — ग्राम सभा बैठक!\n\nदिनांक: [DATE] को पूर्वाहन 11:00 बजे पंचायत भवन में ग्राम सभा की आम बैठक आयोजित की गई है। वित्तीय वर्ष की विकास योजनाओं एवं प्राथमिकताओं के चयन हेतु सभी सम्मानित मतदाता एवं युवा सादर आमंत्रित हैं।\n\n— [NAME], [POST]\n[AREA]",
        facebook: "लोकतंत्र की सबसे मजबूत इकाई 'ग्राम सभा' में अपनी भागीदारी सुनिश्चित करें। आगामी बैठक में गाँव की शिक्षा, स्वास्थ्य, स्वच्छता और सड़क योजनाओं पर खुली चर्चा होगी। #GramSabha #LocalGovernance #LocalNeta"
    }
};

function generateContent() {
    const topic = document.getElementById('genTopic')?.value || 'chhath';
    const name = document.getElementById('posterName')?.value || 'Representative';
    const post = document.getElementById('posterPost')?.value || 'Mukhiya';
    const area = document.getElementById('posterArea')?.value || 'Panchayat Area';

    const presets = PRESET_COPY[topic] || PRESET_COPY['chhath'];

    // Slogans list
    const sloganBox = document.getElementById('generatedSlogansList');
    if (sloganBox) {
        let html = '';
        presets.slogans.forEach((slog, i) => {
            html += `
                <div style="background:#FFFFFF; border:1px solid var(--border-color); border-radius:8px; padding:10px 14px; display:flex; justify-content:space-between; align-items:center; gap:10px;">
                    <span style="font-size:0.9rem; color:var(--navy-main); font-weight:600;">“ ${slog} ”</span>
                    <button type="button" class="btn-profile-view" style="flex:unset; padding:4px 10px; font-size:0.78rem;" onclick="applySloganToPoster('${slog.replace(/'/g, "\\'")}')">
                        Use in Poster
                    </button>
                </div>
            `;
        });
        sloganBox.innerHTML = html;
    }

    // WhatsApp Message
    const waBox = document.getElementById('genWhatsappText');
    if (waBox) {
        const text = presets.whatsapp.replace(/\[NAME\]/g, name).replace(/\[POST\]/g, post).replace(/\[AREA\]/g, area);
        waBox.value = text;
    }

    // Facebook Caption
    const fbBox = document.getElementById('genFacebookText');
    if (fbBox) {
        fbBox.value = presets.facebook;
    }

    showToast('✨ Smart content generated!');
}

function applySloganToPoster(slogan) {
    const el = document.getElementById('posterSlogan');
    if (el) {
        el.value = slogan;
        renderPoster();
        showToast('Applied slogan to poster preview!');
        window.scrollTo({ top: 100, behavior: 'smooth' });
    }
}

function copyToClipboard(elementId) {
    const el = document.getElementById(elementId);
    if (!el) return;
    el.select();
    navigator.clipboard.writeText(el.value).then(() => {
        showToast('📋 Copied to clipboard!');
    });
}

