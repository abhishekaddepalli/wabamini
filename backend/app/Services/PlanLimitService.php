<?php

namespace App\Services;

use App\Models\Tenant;
use App\Models\ChannelConnection;

class PlanLimitService
{
    /**
     * Standard channel display names for user-friendly error messages.
     */
    public const CHANNEL_LABELS = [
        'whatsapp' => 'WhatsApp Cloud API',
        'whatsapp_baileys' => 'WhatsApp Baileys (Unofficial)',
        'telegram' => 'Telegram',
        'instagram' => 'Instagram',
        'messenger' => 'Facebook Messenger',
        'sms' => 'SMS Gateway',
        'email' => 'Email',
        'live_chat' => 'Live Chat',
    ];

    /**
     * Standard individual integration display names.
     */
    public const INTEGRATION_LABELS = [
        'google_sheets' => 'Google Sheets',
        'shopify' => 'Shopify',
        'woocommerce' => 'WooCommerce',
        'zoom' => 'Zoom Meetings',
        'teams' => 'Microsoft Teams',
        'hubspot' => 'HubSpot CRM',
        'salesforce' => 'Salesforce CRM',
        'zoho' => 'Zoho CRM',
        'zapier' => 'Zapier Webhooks',
        'n8n' => 'n8n Automation',
        // Group fallbacks
        'ecommerce' => 'E-Commerce',
        'meetings' => 'Video Meetings',
        'crm' => 'CRM',
        'webhooks' => 'Automation Webhooks',
    ];

    /**
     * Map individual integration keys to group key for backward compatibility.
     */
    public const INTEGRATION_GROUPS = [
        'shopify' => 'ecommerce',
        'woocommerce' => 'ecommerce',
        'zoom' => 'meetings',
        'teams' => 'meetings',
        'hubspot' => 'crm',
        'salesforce' => 'crm',
        'zoho' => 'crm',
        'zapier' => 'webhooks',
        'n8n' => 'webhooks',
    ];

    public const MESSAGES = [
        'en' => [
            'CHANNEL_NOT_ALLOWED_IN_PLAN' => "The :channel channel is not included in your current subscription plan (:plan). Please upgrade your plan to unlock this channel.",
            'CHANNEL_CAP_REACHED' => "You have reached the maximum allowed :channel connections (:limit) on your :plan plan. Please upgrade your plan to add more.",
            'MAX_CHANNELS_LIMIT_REACHED' => "You have reached your total channel limit (:limit channels) for your subscription plan. Please upgrade to connect more channels.",
            'INTEGRATION_NOT_ALLOWED_IN_PLAN' => "The :integration integration is not included in your current subscription plan (:plan). Please upgrade your plan to unlock this integration.",
            'MAX_INTEGRATIONS_LIMIT_REACHED' => "You have reached your total integrations limit (:limit integrations) for your subscription plan. Please upgrade to connect more integrations.",
            'TEMPLATES_NOT_INCLUDED_IN_PLAN' => "Pre-built Flow Templates are not included in your current subscription plan (:plan). Please upgrade your plan to access template blueprints.",
            'MAX_CAMPAIGNS_LIMIT_REACHED' => "You have reached your total campaigns limit (:limit campaigns) for your subscription plan. Please upgrade to launch more campaigns.",
            'MAX_AUTOMATIONS_LIMIT_REACHED' => "You have reached your total automation flows limit (:limit automations) for your subscription plan. Please upgrade to create more automation flows.",
            'SEAT_LIMIT_REACHED' => "Seat limit reached. Upgrade your subscription plan to invite more team members.",
            'CRM_GATED' => "CRM Kanban Deals Pipeline is gated. Please upgrade your subscription tier to access deals.",
            'FLOW_CREDITS_EXHAUSTED' => "AI Flow generation credits exhausted. Upgrade your plan to receive more credits.",
            'TENANT_SUSPENDED' => "Your workspace is currently suspended. Please contact support or resolve billing.",
        ],
        'hi' => [
            'CHANNEL_NOT_ALLOWED_IN_PLAN' => "आपके वर्तमान सब्सक्रिप्शन प्लान (:plan) में :channel चैनल शामिल नहीं है। इस चैनल को अनलॉक करने के लिए कृपया अपने प्लान को अपग्रेड करें।",
            'CHANNEL_CAP_REACHED' => "आप अपने :plan प्लान पर अधिकतम अनुमत :channel कनेक्शन (:limit) तक पहुँच चुके हैं। और जोड़ने के लिए कृपया अपना प्लान अपग्रेड करें।",
            'MAX_CHANNELS_LIMIT_REACHED' => "आप अपने सब्सक्रिप्शन प्लान के लिए कुल चैनल सीमा (:limit चैनल) तक पहुँच चुके हैं। अधिक चैनल जोड़ने के लिए कृपया अपग्रेड करें।",
            'INTEGRATION_NOT_ALLOWED_IN_PLAN' => "आपके वर्तमान सब्सक्रिप्शन प्लान (:plan) में :integration एकीकरण शामिल नहीं है। इसे अनलॉक करने के लिए कृपया अपना प्लान अपग्रेड करें।",
            'MAX_INTEGRATIONS_LIMIT_REACHED' => "आप अपने सब्सक्रिप्शन प्लान के लिए कुल एकीकरण सीमा (:limit एकीकरण) तक पहुँच चुके हैं। अधिक एकीकरण जोड़ने के लिए कृपया अपग्रेड करें।",
            'TEMPLATES_NOT_INCLUDED_IN_PLAN' => "आपके वर्तमान सब्सक्रिप्शन प्लान (:plan) में फ्लो टेम्पलेट्स शामिल नहीं हैं। टेम्पलेट ब्लूप्रिंट का उपयोग करने के लिए कृपया अपग्रेड करें।",
            'MAX_CAMPAIGNS_LIMIT_REACHED' => "आप अपने सब्सक्रिप्शन प्लान के लिए कुल अभियान सीमा (:limit अभियान) तक पहुँच चुके हैं। और अभियान चलाने के लिए कृपया अपग्रेड करें।",
            'MAX_AUTOMATIONS_LIMIT_REACHED' => "आप अपने सब्सक्रिप्शन प्लान के लिए कुल ऑटोमेशन फ्लो सीमा (:limit ऑटोमेशन) तक पहुँच चुके हैं। अधिक फ्लो बनाने के लिए कृपया अपग्रेड करें।",
            'SEAT_LIMIT_REACHED' => "सीट सीमा समाप्त हो गई है। अधिक टीम सदस्यों को आमंत्रित करने के लिए अपना सब्सक्रिप्शन प्लान अपग्रेड करें।",
            'CRM_GATED' => "CRM कानबान डील्स पाइपलाइन प्रतिबंधित है। डील्स तक पहुँचने के लिए कृपया अपना सब्सक्रिप्शन टियर अपग्रेड करें।",
            'FLOW_CREDITS_EXHAUSTED' => "AI फ्लो निर्माण क्रेडिट समाप्त हो गए हैं। अधिक क्रेडिट प्राप्त करने के लिए अपना प्लान अपग्रेड करें।",
            'TENANT_SUSPENDED' => "आपका वर्कस्पेस वर्तमान में निलंबित है। कृपया सहायता से संपर्क करें या बिलिंग का समाधान करें।",
        ],
        'es' => [
            'CHANNEL_NOT_ALLOWED_IN_PLAN' => "El canal :channel no está incluido en su plan de suscripción actual (:plan). Actualice su plan para desbloquear este canal.",
            'CHANNEL_CAP_REACHED' => "Ha alcanzado el límite máximo permitido de conexiones de :channel (:limit) en su plan :plan. Actualice su plan para agregar más.",
            'MAX_CHANNELS_LIMIT_REACHED' => "Ha alcanzado el límite total de canales (:limit canales) para su plan de suscripción. Actualice para conectar más canales.",
            'INTEGRATION_NOT_ALLOWED_IN_PLAN' => "La integración de :integration no está incluida en su plan de suscripción actual (:plan). Actualice su plan para desbloquearla.",
            'MAX_INTEGRATIONS_LIMIT_REACHED' => "Ha alcanzado el límite total de integraciones (:limit integraciones) para su plan de suscripción. Actualice para conectar más integraciones.",
            'TEMPLATES_NOT_INCLUDED_IN_PLAN' => "Las plantillas de flujo prediseñadas no están incluidas en su plan de suscripción actual (:plan). Actualice su plan para acceder a los esquemas de plantillas.",
            'MAX_CAMPAIGNS_LIMIT_REACHED' => "Ha alcanzado el límite total de campañas (:limit campañas) para su plan de suscripción. Actualice para lanzar más campañas.",
            'MAX_AUTOMATIONS_LIMIT_REACHED' => "Ha alcanzado el límite total de flujos de automatización (:limit automatizaciones) para su plan de suscripción. Actualice para crear más flujos.",
            'SEAT_LIMIT_REACHED' => "Límite de asientos alcanzado. Actualice su plan de suscripción para invitar a más miembros del equipo.",
            'CRM_GATED' => "El pipeline de acuerdos CRM Kanban está restringido. Actualice su nivel de suscripción para acceder a los acuerdos.",
            'FLOW_CREDITS_EXHAUSTED' => "Créditos de generación de flujos por IA agotados. Actualice su plan para recibir más créditos.",
            'TENANT_SUSPENDED' => "Su espacio de trabajo está actualmente suspendido. Comuníquese con soporte o resuelva la facturación.",
        ],
        'ar' => [
            'CHANNEL_NOT_ALLOWED_IN_PLAN' => "قناة :channel غير مدرجة في خطة اشتراكك الحالية (:plan). يرجى ترقية خطتك لفتح هذه القناة.",
            'CHANNEL_CAP_REACHED' => "لقد بلغت الحد الأقصى المسموح به لاتصالات :channel (:limit) في خطتك :plan. يرجى ترقية خطتك لإضافة المزيد.",
            'MAX_CHANNELS_LIMIT_REACHED' => "لقد بلغت الحد الإجمالي للقنوات (:limit قنوات) لخطة اشتراكك. يرجى الترقية لربط المزيد من القنوات.",
            'INTEGRATION_NOT_ALLOWED_IN_PLAN' => "تكامل :integration غير مدرج في خطة اشتراكك الحالية (:plan). يرجى ترقية خطتك لفتح هذا التكامل.",
            'MAX_INTEGRATIONS_LIMIT_REACHED' => "لقد بلغت الحد الإجمالي للتكاملات (:limit تكاملات) لخطة اشتراكك. يرجى الترقية لربط المزيد من التكاملات.",
            'TEMPLATES_NOT_INCLUDED_IN_PLAN' => "قوالب المسارات الجاهزة غير مدرجة في خطة اشتراكك الحالية (:plan). يرجى ترقية خطتك للوصول إلى مكتبة القوالب.",
            'MAX_CAMPAIGNS_LIMIT_REACHED' => "لقد بلغت الحد الإجمالي للحملات (:limit حملات) لخطة اشتراكك. يرجى الترقية لإطلاق المزيد من الحملات.",
            'MAX_AUTOMATIONS_LIMIT_REACHED' => "لقد بلغت الحد الإجمالي لمسارات الأتمتة (:limit أتمتة) لخطة اشتراكك. يرجى الترقية لإنشاء المزيد من المسارات.",
            'SEAT_LIMIT_REACHED' => "تم الوصول إلى حد المقاعد. قم بترقية خطة اشتراكك لدعوة المزيد من أعضاء الفريق.",
            'CRM_GATED' => "مسار صفقات كانبان CRM مقيد. يرجى ترقية فئة اشتراكك للوصول إلى الصفقات.",
            'FLOW_CREDITS_EXHAUSTED' => "نفدت أرصدة توليد المسارات بالذكاء الاصطناعي. قم بترقية خطتك للحصول على المزيد من الأرصدة.",
            'TENANT_SUSPENDED' => "مساحة العمل الخاصة بك معلقة حاليًا. يرجى الاتصال بالدعم أو تسوية الفواتير.",
        ],
        'fr' => [
            'CHANNEL_NOT_ALLOWED_IN_PLAN' => "Le canal :channel n'est pas inclus dans votre forfait actuel (:plan). Veuillez mettre à niveau votre forfait pour débloquer ce canal.",
            'CHANNEL_CAP_REACHED' => "Vous avez atteint le nombre maximum de connexions :channel autorisées (:limit) sur votre forfait :plan. Mettez à niveau votre forfait pour en ajouter.",
            'MAX_CHANNELS_LIMIT_REACHED' => "Vous avez atteint votre limite totale de canaux (:limit canaux) pour votre forfait. Mettez à niveau pour connecter plus de canaux.",
            'INTEGRATION_NOT_ALLOWED_IN_PLAN' => "L'intégration :integration n'est pas incluse dans votre forfait actuel (:plan). Veuillez mettre à niveau votre forfait pour débloquer cette intégration.",
            'MAX_INTEGRATIONS_LIMIT_REACHED' => "Vous avez atteint votre limite totale d'intégrations (:limit intégrations) pour votre forfait. Mettez à niveau pour connecter plus d'intégrations.",
            'TEMPLATES_NOT_INCLUDED_IN_PLAN' => "Les modèles de flux prédéfinis ne sont pas inclus dans votre forfait actuel (:plan). Veuillez mettre à niveau votre forfait pour y accéder.",
            'MAX_CAMPAIGNS_LIMIT_REACHED' => "Vous avez atteint votre limite totale de campagnes (:limit campagnes) pour votre forfait. Mettez à niveau pour lancer plus de campagnes.",
            'MAX_AUTOMATIONS_LIMIT_REACHED' => "Vous avez atteint votre limite totale de flux d'automatisation (:limit automatisations) pour votre forfait. Mettez à niveau pour en créer davantage.",
            'SEAT_LIMIT_REACHED' => "Limite de sièges atteinte. Mettez à niveau votre forfait pour inviter plus de membres.",
            'CRM_GATED' => "Le pipeline des opportunités CRM Kanban est restreint. Veuillez mettre à niveau votre forfait pour y accéder.",
            'FLOW_CREDITS_EXHAUSTED' => "Crédits de génération IA épuisés. Mettez à niveau votre forfait pour recevoir plus de crédits.",
            'TENANT_SUSPENDED' => "Votre espace de travail est actuellement suspendu. Veuillez contacter l'assistance ou régler la facturation.",
        ],
        'he' => [
            'CHANNEL_NOT_ALLOWED_IN_PLAN' => "ערוץ :channel אינו כלול בתוכנית המנוי הנוכחית שלך (:plan). אנא שדרג את התוכנית שלך כדי לפתוח ערוץ זה.",
            'CHANNEL_CAP_REACHED' => "הגעת למספר המרבי של חיבורי :channel המותרים (:limit) בתוכנית :plan שלך. אנא שדרג את התוכנית כדי להוסיף עוד.",
            'MAX_CHANNELS_LIMIT_REACHED' => "הגעת למגבלת הערוצים הכוללת (:limit ערוצים) עבור תוכנית המנוי שלך. אנא שדרג כדי לחבר ערוצים נוספים.",
            'INTEGRATION_NOT_ALLOWED_IN_PLAN' => "אינטגרציית :integration אינה כלולה בתוכנית המנוי הנוכחית שלך (:plan). אנא שדרג את התוכנית שלך כדי לפתוח אינטגרציה זו.",
            'MAX_INTEGRATIONS_LIMIT_REACHED' => "הגעת למגבלת האינטגרציות הכוללת (:limit אינטגרציות) עבור תוכנית המנוי שלך. אנא שדרג כדי לחבר עוד אינטגרציות.",
            'TEMPLATES_NOT_INCLUDED_IN_PLAN' => "תבניות זרימה מובנות אינן כלולות בתוכנית המנוי הנוכחית שלך (:plan). אנא שדרג את התוכנית כדי לגשת לתבניות.",
            'MAX_CAMPAIGNS_LIMIT_REACHED' => "הגעת למגבלת הקמפיינים הכוללת (:limit קמפיינים) עבור תוכנית המנוי שלך. אנא שדרג כדי להשיק קמפיינים נוספים.",
            'MAX_AUTOMATIONS_LIMIT_REACHED' => "הגעת למגבלת זרימות האוטומציה הכוללת (:limit אוטומציות) עבור תוכנית המנוי שלך. אנא שדרג כדי ליצור זרימות נוספות.",
            'SEAT_LIMIT_REACHED' => "הגעת למגבלת המושבים. שדרג את תוכנית המנוי שלך כדי להזמין חברי צוות נוספים.",
            'CRM_GATED' => "צינור עסקאות ה-CRM של קנבן חסום. אנא שדרג את רמת המנוי שלך כדי לגשת לעסקאות.",
            'FLOW_CREDITS_EXHAUSTED' => "קרדיטי יצירת זרימות ה-AI אזלו. שדרג את התוכנית שלך כדי לקבל קרדיטים נוספים.",
            'TENANT_SUSPENDED' => "סביבת העבודה שלך מושעית כעת. אנא צור קשר עם התמיכה או הסדר את החיוב.",
        ],
        'id' => [
            'CHANNEL_NOT_ALLOWED_IN_PLAN' => "Saluran :channel tidak termasuk dalam paket langganan Anda saat ini (:plan). Silakan tingkatkan paket Anda untuk membuka saluran ini.",
            'CHANNEL_CAP_REACHED' => "Anda telah mencapai batas maksimum koneksi :channel yang diizinkan (:limit) pada paket :plan Anda. Silakan tingkatkan paket untuk menambah lebih banyak.",
            'MAX_CHANNELS_LIMIT_REACHED' => "Anda telah mencapai total batas saluran (:limit saluran) untuk paket langganan Anda. Silakan tingkatkan untuk menghubungkan lebih banyak saluran.",
            'INTEGRATION_NOT_ALLOWED_IN_PLAN' => "Integrasi :integration tidak termasuk dalam paket langganan Anda saat ini (:plan). Silakan tingkatkan paket Anda untuk membuka integrasi ini.",
            'MAX_INTEGRATIONS_LIMIT_REACHED' => "Anda telah mencapai total batas integrasi (:limit integrasi) untuk paket langganan Anda. Silakan tingkatkan untuk menghubungkan lebih banyak integrasi.",
            'TEMPLATES_NOT_INCLUDED_IN_PLAN' => "Templat Alur siap pakai tidak termasuk dalam paket langganan Anda saat ini (:plan). Silakan tingkatkan paket Anda untuk mengakses cetak biru templat.",
            'MAX_CAMPAIGNS_LIMIT_REACHED' => "Anda telah mencapai total batas kampanye (:limit kampanye) untuk paket langganan Anda. Silakan tingkatkan untuk meluncurkan lebih banyak kampanye.",
            'MAX_AUTOMATIONS_LIMIT_REACHED' => "Anda telah mencapai total batas alur otomatisasi (:limit otomatisasi) untuk paket langganan Anda. Silakan tingkatkan untuk membuat lebih banyak alur.",
            'SEAT_LIMIT_REACHED' => "Batas kursi tercapai. Tingkatkan paket langganan Anda untuk mengundang lebih banyak anggota tim.",
            'CRM_GATED' => "Pipeline Penawaran CRM Kanban dibatasi. Silakan tingkatkan tingkat langganan Anda untuk mengakses penawaran.",
            'FLOW_CREDITS_EXHAUSTED' => "Kredit pembuatan alur AI telah habis. Tingkatkan paket Anda untuk menerima lebih banyak kredit.",
            'TENANT_SUSPENDED' => "Ruang kerja Anda saat ini ditangguhkan. Silakan hubungi dukungan atau selesaikan tagihan.",
        ],
        'it' => [
            'CHANNEL_NOT_ALLOWED_IN_PLAN' => "Il canale :channel non è incluso nel tuo piano di abbonamento attuale (:plan). Effettua l'upgrade del piano per sbloccare questo canale.",
            'CHANNEL_CAP_REACHED' => "Hai raggiunto il numero massimo di connessioni :channel consentite (:limit) sul tuo piano :plan. Effettua l'upgrade per aggiungerne altre.",
            'MAX_CHANNELS_LIMIT_REACHED' => "Hai raggiunto il limite totale di canali (:limit canali) per il tuo piano di abbonamento. Effettua l'upgrade per connettere più canali.",
            'INTEGRATION_NOT_ALLOWED_IN_PLAN' => "L'integrazione :integration non è inclusa nel tuo piano di abbonamento attuale (:plan). Effettua l'upgrade del piano per sbloccarla.",
            'MAX_INTEGRATIONS_LIMIT_REACHED' => "Hai raggiunto il limite totale di integrazioni (:limit integrazioni) per il tuo piano di abbonamento. Effettua l'upgrade per connettere più integrazioni.",
            'TEMPLATES_NOT_INCLUDED_IN_PLAN' => "I modelli di flusso predefiniti non sono inclusi nel tuo piano di abbonamento attuale (:plan). Effettua l'upgrade per accedere ai modelli.",
            'MAX_CAMPAIGNS_LIMIT_REACHED' => "Hai raggiunto il limite totale di campagne (:limit campagne) per il tuo piano di abbonamento. Effettua l'upgrade per lanciare altre campagne.",
            'MAX_AUTOMATIONS_LIMIT_REACHED' => "Hai raggiunto il limite totale di flussi di automazione (:limit automazioni) per il tuo piano. Effettua l'upgrade per creare più flussi.",
            'SEAT_LIMIT_REACHED' => "Limite di postazioni raggiunto. Effettua l'upgrade del tuo piano per invitare altri membri del team.",
            'CRM_GATED' => "La pipeline delle trattative CRM Kanban è riservata. Effettua l'upgrade del livello di abbonamento per accedere alle trattative.",
            'FLOW_CREDITS_EXHAUSTED' => "Crediti di generazione flussi AI esauriti. Effettua l'upgrade del piano per ricevere altri crediti.",
            'TENANT_SUSPENDED' => "Il tuo spazio di lavoro è attualmente sospeso. Contatta l'assistenza o verifica i pagamenti.",
        ],
        'pt' => [
            'CHANNEL_NOT_ALLOWED_IN_PLAN' => "O canal :channel não está incluído no seu plano de assinatura atual (:plan). Atualize seu plano para desbloquear este canal.",
            'CHANNEL_CAP_REACHED' => "Você atingiu o número máximo de conexões de :channel permitidas (:limit) no seu plano :plan. Atualize seu plano para adicionar mais.",
            'MAX_CHANNELS_LIMIT_REACHED' => "Você atingiu o limite total de canais (:limit canais) para o seu plano de assinatura. Atualize para conectar mais canais.",
            'INTEGRATION_NOT_ALLOWED_IN_PLAN' => "A integração :integration não está incluída no seu plano de assinatura atual (:plan). Atualize seu plano para desbloquear esta integração.",
            'MAX_INTEGRATIONS_LIMIT_REACHED' => "Você atingiu o limite total de integrações (:limit integrações) para o seu plano de assinatura. Atualize para conectar mais integrações.",
            'TEMPLATES_NOT_INCLUDED_IN_PLAN' => "Os modelos de fluxo pré-criados não estão incluídos no seu plano de assinatura atual (:plan). Atualize seu plano para acessar os modelos.",
            'MAX_CAMPAIGNS_LIMIT_REACHED' => "Você atingiu o limite total de campanhas (:limit campanhas) para o seu plano de assinatura. Atualize para lançar mais campanhas.",
            'MAX_AUTOMATIONS_LIMIT_REACHED' => "Você atingiu o limite total de fluxos de automação (:limit automações) para o seu plano. Atualize para criar mais fluxos.",
            'SEAT_LIMIT_REACHED' => "Limite de assentos atingido. Atualize seu plano de assinatura para convidar mais membros da equipe.",
            'CRM_GATED' => "O pipeline de negócios CRM Kanban é restrito. Atualize seu nível de assinatura para acessar os negócios.",
            'FLOW_CREDITS_EXHAUSTED' => "Créditos de geração de fluxos por IA esgotados. Atualize seu plano para receber mais créditos.",
            'TENANT_SUSPENDED' => "Seu espaço de trabalho está suspenso no momento. Entre em contato com o suporte ou regularize o faturamento.",
        ],
        'vi' => [
            'CHANNEL_NOT_ALLOWED_IN_PLAN' => "Kênh :channel không có trong gói đăng ký hiện tại của bạn (:plan). Vui lòng nâng cấp gói để mở khóa kênh này.",
            'CHANNEL_CAP_REACHED' => "Bạn đã đạt số lượng kết nối :channel tối đa cho phép (:limit) trên gói :plan. Vui lòng nâng cấp gói để kết nối thêm.",
            'MAX_CHANNELS_LIMIT_REACHED' => "Bạn đã đạt tổng giới hạn kênh (:limit kênh) cho gói đăng ký của mình. Vui lòng nâng cấp để kết nối thêm nhiều kênh.",
            'INTEGRATION_NOT_ALLOWED_IN_PLAN' => "Tích hợp :integration không có trong gói đăng ký hiện tại của bạn (:plan). Vui lòng nâng cấp gói để mở khóa tích hợp này.",
            'MAX_INTEGRATIONS_LIMIT_REACHED' => "Bạn đã đạt tổng giới hạn tích hợp (:limit tích hợp) cho gói đăng ký của mình. Vui lòng nâng cấp để kết nối thêm nhiều tích hợp.",
            'TEMPLATES_NOT_INCLUDED_IN_PLAN' => "Mẫu luồng thiết kế sẵn không có trong gói đăng ký hiện tại của bạn (:plan). Vui lòng nâng cấp gói để sử dụng các mẫu luồng.",
            'MAX_CAMPAIGNS_LIMIT_REACHED' => "Bạn đã đạt tổng giới hạn chiến dịch (:limit chiến dịch) cho gói đăng ký của mình. Vui lòng nâng cấp để tạo thêm chiến dịch.",
            'MAX_AUTOMATIONS_LIMIT_REACHED' => "Bạn đã đạt tổng giới hạn luồng tự động hóa (:limit luồng) cho gói đăng ký của mình. Vui lòng nâng cấp để tạo thêm luồng mới.",
            'SEAT_LIMIT_REACHED' => "Đã đạt giới hạn số lượng thành viên. Hãy nâng cấp gói đăng ký để mời thêm thành viên vào nhóm.",
            'CRM_GATED' => "Quy trình quản lý cơ hội CRM Kanban đang bị giới hạn. Vui lòng nâng cấp gói để truy cập các cơ hội bán hàng.",
            'FLOW_CREDITS_EXHAUSTED' => "Đã hết lượt tạo luồng bằng AI. Hãy nâng cấp gói để nhận thêm lượt tạo luồng.",
            'TENANT_SUSPENDED' => "Không gian làm việc của bạn hiện đang bị tạm khóa. Vui lòng liên hệ hỗ trợ hoặc hoàn tất thanh toán.",
        ],
    ];

    /**
     * Resolve the current preferred user/request locale.
     */
    public static function resolveLocale(): string
    {
        $lang = request()?->header('Accept-Language');
        if ($lang) {
            $code = strtolower(substr($lang, 0, 2));
            if (array_key_exists($code, self::MESSAGES)) {
                return $code;
            }
        }
        $user = auth()->user();
        if ($user && !empty($user->language)) {
            $code = strtolower(substr($user->language, 0, 2));
            if (array_key_exists($code, self::MESSAGES)) {
                return $code;
            }
        }
        return 'en';
    }

    /**
     * Get a localized message string with variable replacements.
     */
    public static function trans(string $code, array $replace = []): string
    {
        $locale = self::resolveLocale();
        $template = self::MESSAGES[$locale][$code] ?? self::MESSAGES['en'][$code] ?? $code;
        foreach ($replace as $key => $value) {
            $template = str_replace(':' . $key, (string) $value, $template);
        }
        return $template;
    }

    /**
     * Check if a tenant has access to a specific feature and check if they've exceeded their limits.
     *
     * @param Tenant $tenant
     * @param string $feature The feature to check (team_members, campaigns, integrations, crm_access, channels, automations)
     * @param int $currentCount The current count of resource records
     * @return bool True if allowed, false if limit exceeded or feature disabled.
     */
    public function canUseFeature(Tenant $tenant, string $feature, int $currentCount = 0): bool
    {
        // If tenant is suspended, deny all access
        if ($tenant->status === 'suspended') {
            return false;
        }

        $plan = $tenant->plan;
        
        // If no plan, check if they are in trial status and define default trial limits
        if (!$plan) {
            if ($tenant->status === 'trial') {
                $trialLimits = [
                    'team_members' => 3,
                    'campaigns' => 2,
                    'integrations' => 1,
                    'crm_access' => false,
                    'channels' => 2,
                    'automations' => 2,
                    'flow_credits' => 5,
                ];

                if ($feature === 'crm_access') {
                    return $trialLimits['crm_access'];
                }

                return isset($trialLimits[$feature]) && $currentCount < $trialLimits[$feature];
            }
            
            return false;
        }

        switch ($feature) {
            case 'team_members':
                return $currentCount < $plan->max_team_members;

            case 'campaigns':
                return $currentCount < $plan->max_campaigns;

            case 'integrations':
                return $currentCount < $plan->max_integrations;

            case 'crm_access':
                return (bool) $plan->own_crm_access;

            case 'channels':
                return $currentCount < $plan->max_channels;

            case 'automations':
                return $currentCount < $plan->max_automations;

            case 'flow_credits':
                return $currentCount < ($plan->flow_credits ?? 50);

            default:
                return false;
        }
    }

    /**
     * Check if tenant can connect a new channel with granular per-channel cap and overall limit.
     *
     * @param Tenant $tenant
     * @param string $channelType (whatsapp, whatsapp_baileys, telegram, instagram, messenger, sms, email, live_chat)
     * @param int $additional Number of channels trying to connect (default 1)
     * @return array ['allowed' => bool, 'reason' => string|null, 'code' => string|null]
     */
    public function canConnectChannel(Tenant $tenant, string $channelType, int $additional = 1): array
    {
        if ($tenant->status === 'suspended') {
            return [
                'allowed' => false,
                'reason' => self::trans('TENANT_SUSPENDED'),
                'code' => 'TENANT_SUSPENDED'
            ];
        }

        $plan = $tenant->plan;
        $channelLabel = self::CHANNEL_LABELS[$channelType] ?? ucfirst(str_replace('_', ' ', $channelType));

        // 1. Granular Allowed Channels & Per-Channel Cap Check (High priority for specific error code)
        if ($plan && is_array($plan->allowed_channels)) {
            $allowedChannels = $plan->allowed_channels;

            // If channelType is not configured or disabled
            if (!array_key_exists($channelType, $allowedChannels) || $allowedChannels[$channelType] === false || $allowedChannels[$channelType] === 0 || $allowedChannels[$channelType] === '0') {
                return [
                    'allowed' => false,
                    'reason' => self::trans('CHANNEL_NOT_ALLOWED_IN_PLAN', [
                        'channel' => $channelLabel,
                        'plan' => $plan->name
                    ]),
                    'code' => 'CHANNEL_NOT_ALLOWED_IN_PLAN'
                ];
            }

            // If a numeric cap is defined per channel type
            $channelCap = is_numeric($allowedChannels[$channelType]) ? (int) $allowedChannels[$channelType] : null;
            if ($channelCap !== null && $channelCap > 0) {
                $currentChannelCount = ChannelConnection::where('tenant_id', $tenant->id)
                    ->where('channel_type', $channelType)
                    ->count();

                if (($currentChannelCount + $additional) > $channelCap) {
                    return [
                        'allowed' => false,
                        'reason' => self::trans('CHANNEL_CAP_REACHED', [
                            'channel' => $channelLabel,
                            'limit' => $channelCap,
                            'plan' => $plan->name
                        ]),
                        'code' => 'CHANNEL_CAP_REACHED',
                        'limit' => $channelCap,
                        'current' => $currentChannelCount
                    ];
                }
            }
        }

        // 2. Overall Channel Connections Count Check
        $totalChannelsCount = ChannelConnection::where('tenant_id', $tenant->id)->count();
        $maxTotalChannels = $plan ? $plan->max_channels : ($tenant->status === 'trial' ? 2 : 0);

        if ($maxTotalChannels > 0 && ($totalChannelsCount + $additional) > $maxTotalChannels) {
            return [
                'allowed' => false,
                'reason' => self::trans('MAX_CHANNELS_LIMIT_REACHED', [
                    'limit' => $maxTotalChannels
                ]),
                'code' => 'MAX_CHANNELS_LIMIT_REACHED',
                'limit' => $maxTotalChannels,
                'current' => $totalChannelsCount
            ];
        }

        return ['allowed' => true, 'reason' => null, 'code' => null];
    }

    /**
     * Check if tenant can use or connect a specific individual integration.
     *
     * @param Tenant $tenant
     * @param string $integrationKey (google_sheets, shopify, woocommerce, zoom, teams, hubspot, salesforce, zoho, zapier, n8n)
     * @return array ['allowed' => bool, 'reason' => string|null, 'code' => string|null]
     */
    public function canUseIntegration(Tenant $tenant, string $integrationKey): array
    {
        if ($tenant->status === 'suspended') {
            return [
                'allowed' => false,
                'reason' => self::trans('TENANT_SUSPENDED'),
                'code' => 'TENANT_SUSPENDED'
            ];
        }

        $plan = $tenant->plan;
        $integrationLabel = self::INTEGRATION_LABELS[$integrationKey] ?? ucfirst(str_replace('_', ' ', $integrationKey));

        // If plan specifies allowed integrations array (even if empty [])
        if ($plan && is_array($plan->allowed_integrations)) {
            $allowed = $plan->allowed_integrations;
            $groupKey = self::INTEGRATION_GROUPS[$integrationKey] ?? null;

            $isAllowed = in_array($integrationKey, $allowed, true)
                || ($groupKey && in_array($groupKey, $allowed, true));

            if (!$isAllowed) {
                return [
                    'allowed' => false,
                    'reason' => self::trans('INTEGRATION_NOT_ALLOWED_IN_PLAN', [
                        'integration' => $integrationLabel,
                        'plan' => $plan->name
                    ]),
                    'code' => 'INTEGRATION_NOT_ALLOWED_IN_PLAN'
                ];
            }
        }

        return ['allowed' => true, 'reason' => null, 'code' => null];
    }

    /**
     * Check if tenant is permitted to browse and instantiate pre-built Flow Templates.
     *
     * @param Tenant $tenant
     * @return array ['allowed' => bool, 'reason' => string|null, 'code' => string|null]
     */
    public function canAccessFlowTemplates(Tenant $tenant): array
    {
        if ($tenant->status === 'suspended') {
            return [
                'allowed' => false,
                'reason' => self::trans('TENANT_SUSPENDED'),
                'code' => 'TENANT_SUSPENDED'
            ];
        }

        $plan = $tenant->plan;

        if ($plan && $plan->has_flow_templates === false) {
            return [
                'allowed' => false,
                'reason' => self::trans('TEMPLATES_NOT_INCLUDED_IN_PLAN', [
                    'plan' => $plan->name
                ]),
                'code' => 'TEMPLATES_NOT_INCLUDED_IN_PLAN'
            ];
        }

        return ['allowed' => true, 'reason' => null, 'code' => null];
    }
}
