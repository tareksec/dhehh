<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\NotifiesWebsiteOfStorefrontChanges;
use App\Services\StorefrontMetaTrackingService;
use App\Support\StorefrontListingCache;
use App\Support\StorefrontSiteDesign;
use App\Support\StorefrontThemeRegistry;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class StorefrontSetting extends Model
{
    use BelongsToCompany;
    use NotifiesWebsiteOfStorefrontChanges;

    /**
     * A company can run more than one storefront, each with its own settings
     * row. `blade` is the server-rendered Blade storefront (the reseller
     * store) and every row that existed before sites were split; `nextjs` is
     * the headless Next.js site (the main storefront), served through
     * /api/v1/storefront/*. At most one row of each type per company.
     */
    public const SITE_BLADE = 'blade';

    public const SITE_NEXTJS = 'nextjs';

    public const SITE_TYPES = [
        self::SITE_BLADE => 'Blade storefront (reseller store)',
        self::SITE_NEXTJS => 'Next.js site (main storefront)',
    ];

    public const CHECKOUT_POLICY_OFF = 'off';

    public const CHECKOUT_POLICY_OBSERVE = 'observe';

    public const CHECKOUT_POLICY_ENFORCE = 'enforce';

    public const CHECKOUT_POLICY_MODES = [
        self::CHECKOUT_POLICY_OFF => 'Off',
        self::CHECKOUT_POLICY_OBSERVE => 'Observe only',
        self::CHECKOUT_POLICY_ENFORCE => 'Enforce',
    ];

    public const RISK_ADVANCE_FIXED = 'fixed';

    public const RISK_ADVANCE_PERCENT = 'percent';

    public const RISK_ADVANCE_TYPES = [
        self::RISK_ADVANCE_FIXED => 'Fixed amount',
        self::RISK_ADVANCE_PERCENT => 'Percentage of order total',
    ];

    public const RISK_ZERO_HISTORY_ALLOW_COD = 'allow_cod';

    public const RISK_ZERO_HISTORY_REQUIRE_ADVANCE = 'require_advance';

    public const RISK_ZERO_HISTORY_ACTIONS = [
        self::RISK_ZERO_HISTORY_ALLOW_COD => 'Allow Cash on Delivery',
        self::RISK_ZERO_HISTORY_REQUIRE_ADVANCE => 'Require the configured advance',
    ];

    public const DEFAULT_RISK_PAYMENT_CUSTOMER_MESSAGE = 'Some orders may require a small verified online advance before confirmation. If required, the secure payment page will show the exact amount and the remaining balance will stay payable on delivery.';

    public const DEFAULT_NEW_CUSTOMER_ADVANCE_MESSAGE = 'ZamZam International চায় কাস্টমারের সাথে একটি সুসম্পর্ক গড়ে উঠুক। পার্সেল নিয়ে কোনো ভুল বোঝাবুঝি এড়াতে নতুন কাস্টমারের অর্ডার শুধু ডেলিভারি চার্জ অগ্রিম পরিশোধের পর নিশ্চিত করা হয়। ভয় পাওয়ার কিছু নেই—আপনার পণ্যের ওজন অনুযায়ী শুধু ডেলিভারি চার্জ অগ্রিম দিতে হবে। পণ্যের বাকি সম্পূর্ণ টাকা হাতে পেয়ে যাচাই করে ডেলিভারি ম্যানকে পরিশোধ করবেন।';

    public const DEFAULT_WHATSAPP_GROUP_URL = 'https://chat.whatsapp.com/ImegB2Visv9BMcI0u5QVUC';

    public const DEFAULT_WHATSAPP_GROUP_MESSAGE = 'ZamZam International-এর প্রোডাক্ট সম্পর্কিত সকল আপডেট এই গ্রুপে পাবেন ইনশাআল্লাহ। জয়েন হয়ে থাকুন—নতুন প্রোডাক্ট ও স্টক আপডেট এখানে জানানো হবে।';

    public const THEME_MODES = [
        'system' => 'Match visitor system setting',
        'light' => 'Light',
        'dark' => 'Dark',
    ];

    public const THEME_FOREGROUND_MODES = [
        'auto' => 'Auto contrast (recommended)',
        'light' => 'Light text',
        'dark' => 'Dark text',
    ];

    public const THEME_PALETTE_PRESETS = [
        // Colours from the ERP Frontend Design System handoff
        // (design_handoff_storefront_themes/README.md, Theme 1).
        'marketplace_pro' => [
            'label' => 'Marketplace Pro',
            'theme_color' => '#0A6CFF',
            'theme_secondary_color' => '#0F2A43',
            'theme_accent_color' => '#FF6A00',
            'theme_background_color' => '#F5F6F8',
            'theme_surface_color' => '#FFFFFF',
            'theme_text_color' => '#14181F',
            'theme_muted_text_color' => '#5C6672',
            'theme_border_color' => '#DDE2E9',
            'theme_dark_background_color' => '#0B1219',
            'theme_dark_surface_color' => '#121C26',
            'theme_dark_text_color' => '#E6EBF0',
            'theme_dark_muted_text_color' => '#9AA7B4',
            'theme_dark_border_color' => '#243241',
        ],
        'noor_solar' => [
            'label' => 'Noor Solar Energy',
            'theme_color' => '#064C38',
            'theme_secondary_color' => '#043628',
            'theme_accent_color' => '#F5BF17',
            'theme_background_color' => '#F7F6F2',
            'theme_surface_color' => '#FFFFFF',
            'theme_text_color' => '#043628',
            'theme_muted_text_color' => '#52635D',
            'theme_border_color' => '#E5E3DA',
            'theme_dark_background_color' => '#031E17',
            'theme_dark_surface_color' => '#073B2C',
            'theme_dark_text_color' => '#F7F6F2',
            'theme_dark_muted_text_color' => '#B9C8C1',
            'theme_dark_border_color' => '#28604E',
        ],
        'emerald' => [
            'label' => 'Emerald Commerce',
            'theme_color' => '#059669',
            'theme_secondary_color' => '#0F766E',
            'theme_accent_color' => '#EA580C',
            'theme_background_color' => '#F8FAFC',
            'theme_surface_color' => '#FFFFFF',
            'theme_text_color' => '#0F172A',
            'theme_muted_text_color' => '#64748B',
            'theme_border_color' => '#E2E8F0',
            'theme_dark_background_color' => '#020617',
            'theme_dark_surface_color' => '#0F172A',
            'theme_dark_text_color' => '#F8FAFC',
            'theme_dark_muted_text_color' => '#94A3B8',
            'theme_dark_border_color' => '#334155',
        ],
        'ocean' => [
            'label' => 'Ocean Blue',
            'theme_color' => '#0369A1',
            'theme_secondary_color' => '#0C4A6E',
            'theme_accent_color' => '#D97706',
            'theme_background_color' => '#F8FAFC',
            'theme_surface_color' => '#FFFFFF',
            'theme_text_color' => '#0F172A',
            'theme_muted_text_color' => '#64748B',
            'theme_border_color' => '#CBD5E1',
            'theme_dark_background_color' => '#020617',
            'theme_dark_surface_color' => '#0F172A',
            'theme_dark_text_color' => '#F8FAFC',
            'theme_dark_muted_text_color' => '#94A3B8',
            'theme_dark_border_color' => '#334155',
        ],
        'royal' => [
            'label' => 'Royal Indigo',
            'theme_color' => '#4F46E5',
            'theme_secondary_color' => '#312E81',
            'theme_accent_color' => '#DB2777',
            'theme_background_color' => '#F8FAFC',
            'theme_surface_color' => '#FFFFFF',
            'theme_text_color' => '#111827',
            'theme_muted_text_color' => '#6B7280',
            'theme_border_color' => '#E5E7EB',
            'theme_dark_background_color' => '#030712',
            'theme_dark_surface_color' => '#111827',
            'theme_dark_text_color' => '#F9FAFB',
            'theme_dark_muted_text_color' => '#9CA3AF',
            'theme_dark_border_color' => '#374151',
        ],
        'luxury' => [
            'label' => 'Premium Black & Gold',
            'theme_color' => '#1C1917',
            'theme_secondary_color' => '#44403C',
            'theme_accent_color' => '#A16207',
            'theme_background_color' => '#FAFAF9',
            'theme_surface_color' => '#FFFFFF',
            'theme_text_color' => '#0C0A09',
            'theme_muted_text_color' => '#78716C',
            'theme_border_color' => '#D6D3D1',
            'theme_dark_background_color' => '#0C0A09',
            'theme_dark_surface_color' => '#1C1917',
            'theme_dark_text_color' => '#FAFAF9',
            'theme_dark_muted_text_color' => '#A8A29E',
            'theme_dark_border_color' => '#44403C',
        ],
        'rose' => [
            'label' => 'Modern Rose',
            'theme_color' => '#BE123C',
            'theme_secondary_color' => '#881337',
            'theme_accent_color' => '#7C3AED',
            'theme_background_color' => '#FFF7F8',
            'theme_surface_color' => '#FFFFFF',
            'theme_text_color' => '#1F1720',
            'theme_muted_text_color' => '#71636D',
            'theme_border_color' => '#F1D5DC',
            'theme_dark_background_color' => '#10080C',
            'theme_dark_surface_color' => '#211116',
            'theme_dark_text_color' => '#FFF7F8',
            'theme_dark_muted_text_color' => '#C4A9B2',
            'theme_dark_border_color' => '#57303C',
        ],
    ];

    public const FONT_OPTIONS = [
        'system' => ['label' => 'System UI', 'family' => "Arial, 'Helvetica Neue', Helvetica, sans-serif", 'query' => null],
        'inter' => ['label' => 'Inter', 'family' => "'Inter', Arial, sans-serif", 'query' => 'Inter:wght@400;500;600;700;800'],
        'sora' => ['label' => 'Sora', 'family' => "'Sora', Arial, sans-serif", 'query' => 'Sora:wght@600;700;800'],
        'rubik' => ['label' => 'Rubik', 'family' => "'Rubik', Arial, sans-serif", 'query' => 'Rubik:wght@400;500;600;700;800'],
        'nunito_sans' => ['label' => 'Nunito Sans', 'family' => "'Nunito Sans', Arial, sans-serif", 'query' => 'Nunito+Sans:wght@400;500;600;700;800'],
        'hind_siliguri' => ['label' => 'Hind Siliguri', 'family' => "'Hind Siliguri', Arial, sans-serif", 'query' => 'Hind+Siliguri:wght@400;500;600;700'],
        'noto_bengali' => ['label' => 'Noto Sans Bengali', 'family' => "'Noto Sans Bengali', Arial, sans-serif", 'query' => 'Noto+Sans+Bengali:wght@400;500;600;700;800'],
        'playfair' => ['label' => 'Playfair Display', 'family' => "'Playfair Display', Georgia, serif", 'query' => 'Playfair+Display:wght@500;600;700;800'],
    ];

    public const TYPOGRAPHY_PRESETS = [
        'noor_solar' => ['label' => 'Noor Solar — Sora & Inter', 'typography_heading_font' => 'sora', 'typography_body_font' => 'inter', 'typography_base_size' => 16, 'typography_heading_weight' => 800, 'typography_body_weight' => 400, 'typography_scale' => 'balanced', 'typography_line_height' => 'normal', 'typography_heading_tracking' => 'tight', 'typography_body_tracking' => 'normal', 'typography_content_width' => 'comfortable'],
        'system' => ['label' => 'System Commerce', 'typography_heading_font' => 'system', 'typography_body_font' => 'system', 'typography_base_size' => 16, 'typography_heading_weight' => 700, 'typography_body_weight' => 400, 'typography_scale' => 'balanced', 'typography_line_height' => 'normal', 'typography_heading_tracking' => 'tight', 'typography_body_tracking' => 'normal', 'typography_content_width' => 'comfortable'],
        'modern' => ['label' => 'Modern Inter', 'typography_heading_font' => 'inter', 'typography_body_font' => 'inter', 'typography_base_size' => 16, 'typography_heading_weight' => 700, 'typography_body_weight' => 400, 'typography_scale' => 'balanced', 'typography_line_height' => 'normal', 'typography_heading_tracking' => 'tight', 'typography_body_tracking' => 'normal', 'typography_content_width' => 'comfortable'],
        'commerce' => ['label' => 'E-commerce Clean', 'typography_heading_font' => 'rubik', 'typography_body_font' => 'nunito_sans', 'typography_base_size' => 16, 'typography_heading_weight' => 700, 'typography_body_weight' => 400, 'typography_scale' => 'balanced', 'typography_line_height' => 'relaxed', 'typography_heading_tracking' => 'tight', 'typography_body_tracking' => 'normal', 'typography_content_width' => 'comfortable'],
        'bengali' => ['label' => 'Bangla Optimized', 'typography_heading_font' => 'hind_siliguri', 'typography_body_font' => 'noto_bengali', 'typography_base_size' => 16, 'typography_heading_weight' => 700, 'typography_body_weight' => 400, 'typography_scale' => 'balanced', 'typography_line_height' => 'relaxed', 'typography_heading_tracking' => 'normal', 'typography_body_tracking' => 'normal', 'typography_content_width' => 'comfortable'],
        'editorial' => ['label' => 'Editorial Premium', 'typography_heading_font' => 'playfair', 'typography_body_font' => 'inter', 'typography_base_size' => 16, 'typography_heading_weight' => 700, 'typography_body_weight' => 400, 'typography_scale' => 'expressive', 'typography_line_height' => 'relaxed', 'typography_heading_tracking' => 'normal', 'typography_body_tracking' => 'normal', 'typography_content_width' => 'narrow'],
    ];

    public const TYPOGRAPHY_SCALES = [
        'compact' => 'Compact',
        'balanced' => 'Balanced',
        'expressive' => 'Expressive',
    ];

    public const TYPOGRAPHY_LINE_HEIGHTS = [
        'tight' => 'Tight',
        'normal' => 'Normal',
        'relaxed' => 'Relaxed',
    ];

    public const TYPOGRAPHY_HEADING_TRACKING = [
        'tight' => 'Tight — modern',
        'normal' => 'Normal',
        'wide' => 'Wide — editorial',
    ];

    public const TYPOGRAPHY_BODY_TRACKING = [
        'compact' => 'Compact',
        'normal' => 'Normal',
        'relaxed' => 'Relaxed',
    ];

    public const TYPOGRAPHY_CONTENT_WIDTHS = [
        'narrow' => 'Narrow — 62 characters',
        'comfortable' => 'Comfortable — 70 characters',
        'wide' => 'Wide — 78 characters',
    ];

    public const APPEARANCE_PRESETS = [
        'modern' => ['label' => 'Modern Commerce', 'appearance_container_width' => 'wide', 'appearance_page_gutter' => 'comfortable', 'appearance_corner_radius' => 'soft', 'appearance_shadow' => 'subtle', 'appearance_motion' => 'standard', 'appearance_card_hover' => 'subtle'],
        'minimal' => ['label' => 'Clean Minimal', 'appearance_container_width' => 'standard', 'appearance_page_gutter' => 'comfortable', 'appearance_corner_radius' => 'sharp', 'appearance_shadow' => 'none', 'appearance_motion' => 'subtle', 'appearance_card_hover' => 'none'],
        'premium' => ['label' => 'Premium Soft UI', 'appearance_container_width' => 'standard', 'appearance_page_gutter' => 'spacious', 'appearance_corner_radius' => 'rounded', 'appearance_shadow' => 'elevated', 'appearance_motion' => 'standard', 'appearance_card_hover' => 'lift'],
        'dense' => ['label' => 'Dense Marketplace', 'appearance_container_width' => 'wide', 'appearance_page_gutter' => 'compact', 'appearance_corner_radius' => 'soft', 'appearance_shadow' => 'subtle', 'appearance_motion' => 'subtle', 'appearance_card_hover' => 'subtle'],
    ];

    public const APPEARANCE_CONTAINER_WIDTHS = [
        'focused' => 'Focused — 72 rem',
        'standard' => 'Standard — 80 rem',
        'wide' => 'Wide marketplace — 92.5 rem',
        'fluid' => 'Fluid — 100%',
    ];

    public const APPEARANCE_PAGE_GUTTERS = [
        'compact' => 'Compact',
        'comfortable' => 'Comfortable',
        'spacious' => 'Spacious',
    ];

    public const APPEARANCE_CORNER_RADII = [
        'sharp' => 'Sharp',
        'soft' => 'Soft',
        'rounded' => 'Rounded',
    ];

    public const APPEARANCE_SHADOWS = [
        'none' => 'Flat / no shadow',
        'subtle' => 'Subtle depth',
        'elevated' => 'Elevated cards',
    ];

    public const APPEARANCE_MOTION = [
        'none' => 'No motion',
        'subtle' => 'Subtle — 150 ms',
        'standard' => 'Standard — 220 ms',
    ];

    public const APPEARANCE_CARD_HOVERS = [
        'none' => 'None',
        'subtle' => 'Subtle emphasis',
        'lift' => 'Premium lift',
    ];

    public const FOOTER_BLOCK_TYPES = [
        'brand_about' => 'Brand & about text',
        'quick_links' => 'Quick links',
        'contact_info' => 'Contact info',
        'social_links' => 'Social links',
        'bottom_bar' => 'Bottom bar (copyright & legal links)',
        'custom_links' => 'Custom links column',
        'custom_text' => 'Custom text',
        'payment_methods' => 'Payment badges',
        'image' => 'Image / badge',
    ];

    // Backward-compatible default: every company created before the footer
    // builder shipped has footer_blocks = NULL, so footerBlocks() falls back
    // to this set — reproducing the previously-hardcoded footer exactly,
    // just as modular blocks instead. See also
    // storefront:seed-default-footer-blocks, which one-time backfills this
    // set into every existing company's footer_blocks column.
    public const DEFAULT_FOOTER_BLOCKS = [
        ['type' => 'brand_about', 'data' => []],
        ['type' => 'quick_links', 'data' => []],
        ['type' => 'contact_info', 'data' => []],
        ['type' => 'bottom_bar', 'data' => []],
    ];

    public const SOCIAL_PLATFORMS = [
        'facebook' => 'Facebook',
        'instagram' => 'Instagram',
        'youtube' => 'YouTube',
        'tiktok' => 'TikTok',
        'x' => 'X (Twitter)',
        'linkedin' => 'LinkedIn',
        'whatsapp' => 'WhatsApp',
    ];

    protected $fillable = [
        'company_id',
        'site_type',
        'storefront_theme',
        'homepage_template',
        'marketplace_announcement_enabled',
        'marketplace_announcement_text',
        'marketplace_quote_enabled',
        'marketplace_business_accounts_enabled',
        'marketplace_business_strip_enabled',
        'banner_pagination_desktop',
        'banner_pagination_mobile',
        'marketplace_trust_strip_enabled',
        'marketplace_deals_enabled',
        'marketplace_categories_enabled',
        'marketplace_bulk_pricing_enabled',
        'marketplace_sidebar_enabled',
        'marketplace_product_limit',
        'marketplace_campaign_badge',
        'marketplace_campaign_heading',
        'marketplace_campaign_subheading',
        'marketplace_campaign_cta_label',
        'marketplace_trust_items',
        'marketplace_bulk_pricing_rows',
        'marketplace_business_heading',
        'marketplace_business_text',
        'marketplace_helpline',
        'marketplace_utility_bar_enabled',
        'marketplace_utility_bar_text',
        'theme_color',
        'theme_foreground_mode',
        'theme_palette_preset',
        'theme_secondary_color',
        'theme_accent_color',
        'theme_background_color',
        'theme_surface_color',
        'theme_text_color',
        'theme_muted_text_color',
        'theme_border_color',
        'theme_dark_background_color',
        'theme_dark_surface_color',
        'theme_dark_text_color',
        'theme_dark_muted_text_color',
        'theme_dark_border_color',
        'typography_preset',
        'typography_heading_font',
        'typography_body_font',
        'typography_base_size',
        'typography_heading_weight',
        'typography_body_weight',
        'typography_scale',
        'typography_line_height',
        'typography_heading_tracking',
        'typography_body_tracking',
        'typography_content_width',
        'appearance_preset',
        'appearance_container_width',
        'appearance_page_gutter',
        'appearance_corner_radius',
        'appearance_shadow',
        'appearance_motion',
        'appearance_card_hover',
        'logo',
        'logo_dark',
        'favicon',
        'customer_accounts_enabled',
        'whatsapp_number',
        'phone_number',
        'contact_email',
        'contact_hours',
        'hero_heading',
        'hero_subheading',
        'hero_cta_label',
        'trust_strip_delivery',
        'trust_strip_return',
        'trust_strip_payment',
        'offer_title',
        'offer_discount_percent',
        'offer_ends_at',
        'theme_mode',
        'online_payment_enabled',
        'online_payment_gateway',
        'payment_credentials',
        'cod_enabled',
        'checkout_policy_mode',
        'checkout_validate_bd_phone',
        'checkout_block_phone',
        'checkout_block_email',
        'checkout_block_ip',
        'checkout_order_limit_enabled',
        'checkout_order_limit_count',
        'checkout_order_limit_hours',
        'checkout_policy_contact_phone',
        'risk_payment_enabled',
        'risk_payment_success_ratio_threshold',
        'risk_payment_advance_type',
        'risk_payment_advance_amount',
        'risk_payment_advance_percent',
        'risk_payment_zero_history_action',
        'risk_payment_customer_message',
        'delivery_charge_inside',
        'delivery_charge_outside',
        'delivery_first_kg_inside',
        'delivery_first_kg_outside',
        'delivery_additional_per_kg',
        'vat_enabled',
        'vat_rate',
        'vat_mode',
        'vat_label',
        'vat_use_product_rate',
        'delivery_time_inside',
        'delivery_time_outside',
        'inside_dhaka_keywords',
        'new_customer_delivery_advance_enabled',
        'new_customer_advance_message',
        'whatsapp_group_url',
        'whatsapp_group_message',
        'complaint_telegram_enabled',
        'telegram_credentials',
        'manual_bkash_number',
        'manual_bkash_instructions',
        'manual_nagad_number',
        'manual_nagad_instructions',
        'abandoned_cart_reminders_enabled',
        'checkout_autosave_enabled',
        'meta_tracking_enabled',
        'meta_consent_required',
        'meta_consent_message',
        'meta_browser_tracking_enabled',
        'meta_advanced_matching_enabled',
        'meta_browser_events',
        'meta_custom_events_enabled',
        'meta_custom_events',
        'meta_capi_enabled',
        'meta_purchase_timing',
        'meta_purchase_success_ratio_threshold',
        'meta_status_events_enabled',
        'meta_status_events',
        'meta_pixel_id',
        'meta_domain_verification_tags',
        'meta_tracking_credentials',
        'abandoned_cart_delay_hours',
        'lead_follow_up_reminders_enabled',
        'notification_credentials',
        'woocommerce_base_url',
        'woocommerce_credentials',
        'website_api_credentials',
        'meta_title',
        'meta_description',
        'header_menu',
        'footer_menu',
        'footer_blocks',
        'home_sections',
        'site_design',
        'reseller_program_enabled',
        'reseller_cost_per_unit',
        'reseller_packaging_cost_per_unit',
        'reseller_return_charge',
        'reseller_min_withdrawal',
        'reseller_subdomain_setup_minutes',
        'reseller_guide_enabled',
        'reseller_guide_intro',
        'reseller_guide_rules',
        'reseller_payment_method_ids',
        'reseller_guide_show_figures',
        'reseller_announcement_enabled',
        'reseller_announcement',
        'reseller_banner_image',
        'reseller_message_approved',
        'reseller_message_profit_payable',
        'reseller_message_payout_paid',
        'social_links',
        'is_published',
        'nextjs_site_enabled',
        'nextjs_site_domain',
        'nextjs_site_domain_verified',
    ];

    protected $casts = [
        'is_published' => 'boolean',
        'nextjs_site_enabled' => 'boolean',
        'nextjs_site_domain_verified' => 'boolean',
        'customer_accounts_enabled' => 'boolean',
        'marketplace_announcement_enabled' => 'boolean',
        'marketplace_quote_enabled' => 'boolean',
        'marketplace_business_accounts_enabled' => 'boolean',
        'marketplace_business_strip_enabled' => 'boolean',
        'banner_pagination_desktop' => 'boolean',
        'banner_pagination_mobile' => 'boolean',
        'marketplace_trust_strip_enabled' => 'boolean',
        'marketplace_deals_enabled' => 'boolean',
        'marketplace_categories_enabled' => 'boolean',
        'marketplace_bulk_pricing_enabled' => 'boolean',
        'marketplace_sidebar_enabled' => 'boolean',
        'marketplace_product_limit' => 'integer',
        'marketplace_trust_items' => 'array',
        'marketplace_bulk_pricing_rows' => 'array',
        'marketplace_utility_bar_enabled' => 'boolean',
        'offer_discount_percent' => 'integer',
        'offer_ends_at' => 'datetime',
        'woocommerce_credentials' => 'encrypted:array',
        'website_api_credentials' => 'encrypted:array',
        'online_payment_enabled' => 'boolean',
        'payment_credentials' => 'encrypted:array',
        'cod_enabled' => 'boolean',
        'checkout_validate_bd_phone' => 'boolean',
        'checkout_block_phone' => 'boolean',
        'checkout_block_email' => 'boolean',
        'checkout_block_ip' => 'boolean',
        'checkout_order_limit_enabled' => 'boolean',
        'checkout_order_limit_count' => 'integer',
        'checkout_order_limit_hours' => 'integer',
        'risk_payment_enabled' => 'boolean',
        'risk_payment_success_ratio_threshold' => 'integer',
        'risk_payment_advance_amount' => 'decimal:2',
        'risk_payment_advance_percent' => 'integer',
        'delivery_charge_inside' => 'decimal:2',
        'delivery_charge_outside' => 'decimal:2',
        'delivery_first_kg_inside' => 'decimal:2',
        'delivery_first_kg_outside' => 'decimal:2',
        'delivery_additional_per_kg' => 'decimal:2',
        'vat_enabled' => 'boolean',
        'vat_rate' => 'decimal:2',
        'vat_use_product_rate' => 'boolean',
        'new_customer_delivery_advance_enabled' => 'boolean',
        'complaint_telegram_enabled' => 'boolean',
        'telegram_credentials' => 'encrypted:array',
        'abandoned_cart_reminders_enabled' => 'boolean',
        'checkout_autosave_enabled' => 'boolean',
        'meta_tracking_enabled' => 'boolean',
        'meta_consent_required' => 'boolean',
        'meta_browser_tracking_enabled' => 'boolean',
        'meta_advanced_matching_enabled' => 'boolean',
        'meta_browser_events' => 'array',
        'meta_custom_events_enabled' => 'boolean',
        'meta_custom_events' => 'array',
        'meta_capi_enabled' => 'boolean',
        'meta_purchase_success_ratio_threshold' => 'integer',
        'meta_status_events_enabled' => 'boolean',
        'meta_status_events' => 'array',
        'meta_domain_verification_tags' => 'array',
        'meta_tracking_credentials' => 'encrypted:array',
        'abandoned_cart_delay_hours' => 'integer',
        'lead_follow_up_reminders_enabled' => 'boolean',
        'notification_credentials' => 'encrypted:array',
        'header_menu' => 'array',
        'footer_menu' => 'array',
        'footer_blocks' => 'array',
        'home_sections' => 'array',
        'site_design' => 'array',
        'reseller_program_enabled' => 'boolean',
        'reseller_cost_per_unit' => 'decimal:2',
        'reseller_packaging_cost_per_unit' => 'decimal:2',
        'reseller_return_charge' => 'decimal:2',
        'reseller_min_withdrawal' => 'decimal:2',
        'reseller_subdomain_setup_minutes' => 'integer',
        'reseller_guide_enabled' => 'boolean',
        'reseller_guide_rules' => 'array',
        'reseller_payment_method_ids' => 'array',
        'reseller_guide_show_figures' => 'boolean',
        'reseller_announcement_enabled' => 'boolean',
        'social_links' => 'array',
        'typography_base_size' => 'integer',
        'typography_heading_weight' => 'integer',
        'typography_body_weight' => 'integer',
    ];

    public function customerAdvanceMessage(): string
    {
        return trim((string) $this->new_customer_advance_message) ?: self::DEFAULT_NEW_CUSTOMER_ADVANCE_MESSAGE;
    }

    public function riskPaymentCustomerMessage(): string
    {
        return trim((string) $this->risk_payment_customer_message) ?: self::DEFAULT_RISK_PAYMENT_CUSTOMER_MESSAGE;
    }

    public function whatsappGroupUrl(): string
    {
        return trim((string) $this->whatsapp_group_url) ?: self::DEFAULT_WHATSAPP_GROUP_URL;
    }

    public function whatsappGroupMessage(): string
    {
        return trim((string) $this->whatsapp_group_message) ?: self::DEFAULT_WHATSAPP_GROUP_MESSAGE;
    }

    public function footerBlocks(): array
    {
        return $this->footer_blocks ?: self::DEFAULT_FOOTER_BLOCKS;
    }

    /**
     * The Next.js home page's sections in order (Home Page Builder), clean and
     * bounded; the default layout while nothing is saved. The Blade storefront
     * does not read this.
     *
     * @return list<array{type: string, data: array<string, mixed>}>
     */
    public function homeSections(): array
    {
        return StorefrontSiteDesign::homeSections($this->home_sections);
    }

    /** Top bar, header, footer colours and floating help button of the Next.js site, defaults filled in. */
    public function siteDesign(): array
    {
        return StorefrontSiteDesign::design($this->site_design);
    }

    public function isNextjs(): bool
    {
        return $this->site_type === self::SITE_NEXTJS;
    }

    public function isBlade(): bool
    {
        return ! $this->isNextjs();
    }

    /**
     * What an order or payment stores to remember its site: this row's id for
     * a Next.js site, NULL for the Blade storefront (the default everywhere).
     */
    public function recordedSiteId(): ?int
    {
        return $this->isNextjs() ? (int) $this->getKey() : null;
    }

    public function scopeBlade(Builder $query): Builder
    {
        return $query->where($this->qualifyColumn('site_type'), self::SITE_BLADE);
    }

    public function scopeNextjs(Builder $query): Builder
    {
        return $query->where($this->qualifyColumn('site_type'), self::SITE_NEXTJS);
    }

    /**
     * The company's Blade storefront settings (the reseller store). This is
     * also what every company-level feature that predates the site split
     * (WooCommerce, CRM messaging, reminders, the reseller program) reads.
     * Looked up without the company scope so it works in jobs and webhooks.
     */
    public static function bladeFor(?int $companyId): ?self
    {
        return $companyId ? static::withoutGlobalScopes()->blade()->where('company_id', $companyId)->first() : null;
    }

    /** The company's Next.js site settings (the main storefront), if it has one. */
    public static function nextjsFor(?int $companyId): ?self
    {
        return $companyId ? static::withoutGlobalScopes()->nextjs()->where('company_id', $companyId)->first() : null;
    }

    /**
     * The row company-level features read (WooCommerce, CRM messaging, lead
     * reminders): the Next.js site once the company has one — it is the main
     * storefront — otherwise the Blade storefront. The reseller store and the
     * Blade carts always use the Blade row (see bladeFor()).
     */
    public static function primaryFor(?int $companyId): ?self
    {
        return static::nextjsFor($companyId) ?? static::bladeFor($companyId);
    }

    public function isPrimary(): bool
    {
        return (bool) static::primaryFor((int) $this->company_id)?->is($this);
    }

    /**
     * "Show on" choices for a slide or page: each of the company's sites,
     * keyed by its settings row id. "All sites" is the empty choice.
     *
     * @return array<int, string>
     */
    public static function siteChoicesFor(?int $companyId): array
    {
        if (! $companyId) {
            return [];
        }

        return static::withoutGlobalScopes()
            ->with('company')
            ->where('company_id', $companyId)
            ->orderBy('site_type')
            ->get()
            ->mapWithKeys(fn (self $row): array => [$row->getKey() => $row->siteTypeLabel().' · '.($row->siteDomain() ?: '—')])
            ->all();
    }

    /** Domain this site is served on: the company's storefront domain, or the Next.js site's own. */
    public function siteDomain(): ?string
    {
        return $this->isNextjs() ? $this->nextjs_site_domain : $this->company?->domain;
    }

    public function siteTypeLabel(): string
    {
        return __(self::SITE_TYPES[$this->site_type ?: self::SITE_BLADE] ?? self::SITE_TYPES[self::SITE_BLADE]);
    }

    /**
     * Whether the company's headless Next.js storefront may read the ERP's
     * /api/v1/storefront/* endpoints. A domain is not required to be set yet
     * so the site can be developed against the API before it goes live.
     */
    public function nextjsSiteEnabled(): bool
    {
        return $this->isNextjs() && (bool) $this->nextjs_site_enabled;
    }

    /**
     * Whether $host is the Next.js site domain of a company other than
     * $exceptCompanyId. Looked up without the company scope: a domain is
     * global across all companies, exactly like `companies.domain`.
     */
    public static function nextjsDomainTaken(?string $host, ?int $exceptCompanyId = null): bool
    {
        $host = Company::normalizeDomain($host);

        if ($host === null) {
            return false;
        }

        return static::withoutGlobalScopes()
            ->nextjs()
            ->where('nextjs_site_domain', $host)
            ->when($exceptCompanyId, fn ($query) => $query->where('company_id', '!=', $exceptCompanyId))
            ->exists();
    }

    /**
     * Why $domain cannot be a company's Next.js site domain, or null when it
     * is free. A domain is global: it may not already be a storefront or
     * reseller-portal domain, a reseller's own store domain, or another
     * company's Next.js site.
     */
    public static function nextjsDomainConflict(?string $domain, ?int $companyId = null): ?string
    {
        $host = Company::normalizeDomain($domain);

        if ($host === null) {
            return null;
        }

        $usedByCompany = Company::withoutGlobalScopes()
            ->where(fn ($query) => $query->where('domain', $host)->orWhere('reseller_portal_domain', $host))
            ->exists();

        if ($usedByCompany) {
            return __('This domain is already used as a storefront or portal domain.');
        }

        if (Customer::withoutGlobalScopes()->where('reseller_custom_domain', $host)->exists()) {
            return __('This domain is already used as a reseller store domain.');
        }

        if (static::nextjsDomainTaken($host, $companyId)) {
            return __('This domain is already used by another Next.js site.');
        }

        return null;
    }

    protected static function booted(): void
    {
        static::creating(function (StorefrontSetting $setting): void {
            $setting->storefront_theme ??= StorefrontThemeRegistry::BUILT_IN;
            $setting->homepage_template ??= StorefrontThemeRegistry::BUILT_IN_DEFAULT;
            $setting->theme_color ??= '#0F766E';
            $setting->theme_foreground_mode ??= 'auto';
            $setting->theme_palette_preset ??= 'custom';
            $setting->typography_preset ??= 'system';
            $setting->typography_heading_font ??= 'system';
            $setting->typography_body_font ??= 'system';
            $setting->typography_base_size ??= 16;
            $setting->typography_heading_weight ??= 700;
            $setting->typography_body_weight ??= 400;
            $setting->typography_scale ??= 'balanced';
            $setting->typography_line_height ??= 'normal';
            $setting->typography_heading_tracking ??= 'tight';
            $setting->typography_body_tracking ??= 'normal';
            $setting->typography_content_width ??= 'comfortable';
            $setting->appearance_preset ??= 'modern';
            $setting->appearance_container_width ??= 'wide';
            $setting->appearance_page_gutter ??= 'comfortable';
            $setting->appearance_corner_radius ??= 'soft';
            $setting->appearance_shadow ??= 'subtle';
            $setting->appearance_motion ??= 'standard';
            $setting->appearance_card_hover ??= 'subtle';
            $setting->is_published ??= false;
            $setting->customer_accounts_enabled ??= true;
            $setting->theme_mode ??= 'system';
            $setting->cod_enabled ??= true;
            $setting->checkout_policy_mode ??= self::CHECKOUT_POLICY_OFF;
            $setting->checkout_validate_bd_phone ??= true;
            $setting->checkout_block_phone ??= true;
            $setting->checkout_block_email ??= true;
            $setting->checkout_block_ip ??= true;
            $setting->checkout_order_limit_enabled ??= false;
            $setting->checkout_order_limit_count ??= 1;
            $setting->checkout_order_limit_hours ??= 24;
            $setting->risk_payment_enabled ??= false;
            $setting->risk_payment_success_ratio_threshold ??= 70;
            $setting->risk_payment_advance_type ??= self::RISK_ADVANCE_FIXED;
            $setting->risk_payment_advance_amount ??= 100;
            $setting->risk_payment_advance_percent ??= 20;
            $setting->risk_payment_zero_history_action ??= self::RISK_ZERO_HISTORY_ALLOW_COD;
            $setting->checkout_autosave_enabled ??= false;
            $setting->meta_consent_required ??= false;
            $setting->meta_advanced_matching_enabled ??= false;
            $setting->meta_browser_events ??= StorefrontMetaTrackingService::DEFAULT_BROWSER_EVENTS;
            $setting->meta_custom_events_enabled ??= false;
            $setting->meta_purchase_timing ??= 'immediate';
            $setting->meta_purchase_success_ratio_threshold ??= 70;
            $setting->marketplace_announcement_enabled ??= true;
            $setting->marketplace_quote_enabled ??= true;
            $setting->marketplace_business_accounts_enabled ??= true;
            $setting->marketplace_business_strip_enabled ??= false;
            $setting->banner_pagination_desktop ??= true;
            $setting->banner_pagination_mobile ??= true;
            $setting->marketplace_trust_strip_enabled ??= true;
            $setting->marketplace_deals_enabled ??= true;
            $setting->marketplace_categories_enabled ??= true;
            $setting->marketplace_bulk_pricing_enabled ??= true;
            $setting->marketplace_sidebar_enabled ??= true;
            $setting->marketplace_product_limit ??= 10;
            $setting->reseller_program_enabled ??= true;
            $setting->reseller_guide_enabled ??= true;
            $setting->reseller_guide_show_figures ??= false;
            $setting->reseller_announcement_enabled ??= false;
            $setting->reseller_subdomain_setup_minutes ??= 10;
        });

        static::saved(function (StorefrontSetting $setting): void {
            Cache::forget("storefront-home:{$setting->company_id}");
            StorefrontListingCache::forgetCompany($setting->company_id);
        });
        static::deleted(function (StorefrontSetting $setting): void {
            Cache::forget("storefront-home:{$setting->company_id}");
            StorefrontListingCache::forgetCompany($setting->company_id);
        });

        // Checkout payment options now live entirely in StorefrontPaymentMethod
        // (dashboard-managed, see that model) rather than the cod_enabled/
        // manual_* columns above. Without at least one active row, a brand
        // new company's checkout page would have literally no payment option
        // to offer, so every newly created setting gets a default Cash on
        // Delivery row for free — matching cod_enabled's old implicit
        // "true unless the owner turns it off" default. Existing companies
        // were one-time backfilled by the
        // 2026_08_24_160000_create_storefront_payment_methods_table migration.
        static::created(function (StorefrontSetting $setting): void {
            // withoutGlobalScopes(): CompanyScope filters by whatever the
            // *current request's* CompanyContext happens to be, which is not
            // necessarily $setting->company_id (e.g. an admin creating a
            // second company's settings while their own company is still
            // the active context) — scoping this lookup would miss an
            // existing row and attempt a duplicate insert instead.
            StorefrontPaymentMethod::withoutGlobalScopes()->firstOrCreate(
                ['company_id' => $setting->company_id, 'type' => StorefrontPaymentMethod::TYPE_COD],
                ['name' => 'Cash on Delivery', 'is_active' => true, 'sort_order' => 0],
            );
        });
    }

    public function hasActiveOffer(): bool
    {
        return filled($this->offer_title) && $this->offer_ends_at && $this->offer_ends_at->isFuture();
    }

    /**
     * Whether the hero banner's dot navigation shows on the given display.
     * "desktop" is >= 1024px (the banner's own breakpoint), "mobile" is every
     * width below it. Defaults to visible — the historical behaviour — when
     * the column is absent (older/restored DB) or null. Owner-controlled from
     * Storefront -> Hero Slides.
     */
    public function showsBannerPagination(string $display): bool
    {
        $column = $display === 'mobile' ? 'banner_pagination_mobile' : 'banner_pagination_desktop';

        return (bool) ($this->getAttribute($column) ?? true);
    }

    public function storefrontTheme(): string
    {
        return StorefrontThemeRegistry::normalizeTheme($this->storefront_theme);
    }

    public function homepageTemplate(): string
    {
        return StorefrontThemeRegistry::normalizeTemplate($this->storefrontTheme(), $this->homepage_template);
    }

    public function homepageView(): string
    {
        return StorefrontThemeRegistry::homeView($this->storefrontTheme(), $this->homepageTemplate());
    }

    public function marketplaceProductLimit(): int
    {
        return max(5, min(20, (int) ($this->marketplace_product_limit ?: 10)));
    }

    public const MARKETPLACE_TRUST_ICONS = [
        'truck' => 'Delivery truck',
        'refresh' => 'Return / replacement',
        'briefcase' => 'Business',
        'lock' => 'Secure payment',
        'shield' => 'Warranty / genuine',
        'phone' => 'Support / helpline',
    ];

    /**
     * Marketplace Pro trust strip items, admin-entered only. Falls back to
     * the shared Trust Strip fields (titles only) for stores configured
     * before the dedicated repeater existed; never invents copy.
     *
     * @return list<array{icon: string, title: string, subtitle: ?string}>
     */
    public function marketplaceTrustItems(): array
    {
        $items = collect($this->marketplace_trust_items ?? [])
            ->map(fn ($item): array => [
                'icon' => array_key_exists($item['icon'] ?? '', self::MARKETPLACE_TRUST_ICONS) ? $item['icon'] : 'shield',
                'title' => trim((string) ($item['title'] ?? '')),
                'subtitle' => filled($item['subtitle'] ?? null) ? trim((string) $item['subtitle']) : null,
            ])
            ->filter(fn (array $item): bool => $item['title'] !== '')
            ->values();

        if ($items->isEmpty()) {
            $items = collect([
                ['icon' => 'truck', 'title' => trim((string) $this->trust_strip_delivery), 'subtitle' => null],
                ['icon' => 'refresh', 'title' => trim((string) $this->trust_strip_return), 'subtitle' => null],
                ['icon' => 'lock', 'title' => trim((string) $this->trust_strip_payment), 'subtitle' => null],
            ])->filter(fn (array $item): bool => $item['title'] !== '')->values();
        }

        return $items->take(6)->all();
    }

    /** @return list<array{label: string, value: string}> */
    public function marketplaceBulkPricingRows(): array
    {
        return collect($this->marketplace_bulk_pricing_rows ?? [])
            ->map(fn ($row): array => [
                'label' => trim((string) ($row['label'] ?? '')),
                'value' => trim((string) ($row['value'] ?? '')),
            ])
            ->filter(fn (array $row): bool => $row['label'] !== '' && $row['value'] !== '')
            ->values()
            ->all();
    }

    public function normalizedThemeColor(): string
    {
        return $this->normalizedColor('theme_color', '#0F766E');
    }

    public function themeForegroundColor(): string
    {
        if ($this->theme_foreground_mode === 'light') {
            return '#FFFFFF';
        }

        if ($this->theme_foreground_mode === 'dark') {
            return '#000000';
        }

        return $this->contrastColor($this->normalizedThemeColor());
    }

    public static function colorContrastRatio(string $foreground, string $background): float
    {
        if (! preg_match('/^#[0-9a-f]{6}$/i', $foreground) || ! preg_match('/^#[0-9a-f]{6}$/i', $background)) {
            return 0;
        }

        $foregroundLuminance = self::relativeLuminance($foreground);
        $backgroundLuminance = self::relativeLuminance($background);
        $lighter = max($foregroundLuminance, $backgroundLuminance);
        $darker = min($foregroundLuminance, $backgroundLuminance);

        return ($lighter + 0.05) / ($darker + 0.05);
    }

    public static function themePalettePresetOptions(): array
    {
        return ['custom' => 'Custom palette'] + collect(self::THEME_PALETTE_PRESETS)
            ->mapWithKeys(fn (array $preset, string $key): array => [$key => $preset['label']])
            ->all();
    }

    public static function themePalettePresetFields(?string $preset): array
    {
        return collect(self::THEME_PALETTE_PRESETS[$preset] ?? [])->except('label')->all();
    }

    public static function fontOptions(): array
    {
        return collect(self::FONT_OPTIONS)
            ->mapWithKeys(fn (array $font, string $key): array => [$key => $font['label']])
            ->all();
    }

    public static function typographyPresetOptions(): array
    {
        return ['custom' => 'Custom typography'] + collect(self::TYPOGRAPHY_PRESETS)
            ->mapWithKeys(fn (array $preset, string $key): array => [$key => $preset['label']])
            ->all();
    }

    public static function typographyPresetFields(?string $preset): array
    {
        return collect(self::TYPOGRAPHY_PRESETS[$preset] ?? [])->except('label')->all();
    }

    public static function appearancePresetOptions(): array
    {
        return ['custom' => 'Custom appearance'] + collect(self::APPEARANCE_PRESETS)
            ->mapWithKeys(fn (array $preset, string $key): array => [$key => $preset['label']])
            ->all();
    }

    public static function appearancePresetFields(?string $preset): array
    {
        return collect(self::APPEARANCE_PRESETS[$preset] ?? [])->except('label')->all();
    }

    public function themePalette(): array
    {
        $primary = $this->normalizedThemeColor();
        $secondary = $this->normalizedColor('theme_secondary_color', '#0F172A');
        $accent = $this->normalizedColor('theme_accent_color', '#F59E0B');

        return [
            'primary' => $primary,
            'primary_contrast' => $this->themeForegroundColor(),
            'secondary' => $secondary,
            'secondary_contrast' => $this->contrastColor($secondary),
            // The utility bar and footer carry light text, so they need a dark
            // surface even when the admin picked a light secondary colour.
            'secondary_deep' => self::relativeLuminance($secondary) > 0.179 ? '#0F172A' : $secondary,
            'accent' => $accent,
            'accent_contrast' => $this->contrastColor($accent),
            'background' => $this->normalizedColor('theme_background_color', '#FFFFFF'),
            'surface' => $this->normalizedColor('theme_surface_color', '#FFFFFF'),
            'text' => $this->normalizedColor('theme_text_color', '#111827'),
            'muted_text' => $this->normalizedColor('theme_muted_text_color', '#6B7280'),
            'border' => $this->normalizedColor('theme_border_color', '#E5E7EB'),
            'dark_background' => $this->normalizedColor('theme_dark_background_color', '#030712'),
            'dark_surface' => $this->normalizedColor('theme_dark_surface_color', '#111827'),
            'dark_text' => $this->normalizedColor('theme_dark_text_color', '#F3F4F6'),
            'dark_muted_text' => $this->normalizedColor('theme_dark_muted_text_color', '#9CA3AF'),
            'dark_border' => $this->normalizedColor('theme_dark_border_color', '#374151'),
        ];
    }

    public function headingFontFamily(): string
    {
        return self::FONT_OPTIONS[$this->typography_heading_font]['family'] ?? self::FONT_OPTIONS['system']['family'];
    }

    public function bodyFontFamily(): string
    {
        return self::FONT_OPTIONS[$this->typography_body_font]['family'] ?? self::FONT_OPTIONS['system']['family'];
    }

    public const VAT_MODE_EXCLUSIVE = 'exclusive';

    public const VAT_MODE_INCLUSIVE = 'inclusive';

    public function vatActive(): bool
    {
        return (bool) $this->vat_enabled
            && ((float) $this->vat_rate > 0 || (bool) $this->vat_use_product_rate);
    }

    public function vatInclusive(): bool
    {
        return $this->vat_mode === self::VAT_MODE_INCLUSIVE;
    }

    public function vatLabel(): string
    {
        return filled($this->vat_label) ? trim((string) $this->vat_label) : 'VAT';
    }

    /** "VAT (7.5%)" for a single store-wide rate; just "VAT" when per-product rates may differ. */
    public function vatDisplayLabel(): string
    {
        if ($this->vat_use_product_rate) {
            return $this->vatLabel();
        }

        return $this->vatLabel().' ('.rtrim(rtrim(number_format((float) $this->vat_rate, 2), '0'), '.').'%)';
    }

    /** The VAT rate for one product line: its own ERP rate when enabled and set, else the store rate. */
    public function vatRateFor(?Product $product): float
    {
        if ($this->vat_use_product_rate && $product && (float) $product->vat_rate > 0) {
            return (float) $product->vat_rate;
        }

        return max(0, (float) $this->vat_rate);
    }

    /**
     * VAT added on top of the product lines (delivery is not taxed). Only
     * exclusive mode adds anything: the result goes into orders.vat and the
     * order total. Inclusive mode returns 0 because product prices already
     * contain VAT — see vatIncludedInItems() for the display-only share.
     *
     * @param  iterable<array{subtotal: float|int|string, product?: ?Product}>  $items
     */
    public function vatAmountForItems(iterable $items): float
    {
        if (! $this->vatActive() || $this->vatInclusive()) {
            return 0.0;
        }

        return round(collect($items)->sum(
            fn (array $item): float => max(0, (float) $item['subtotal']) * $this->vatRateFor($item['product'] ?? null) / 100
        ), 2);
    }

    /**
     * The VAT share already contained in inclusive-priced product lines.
     *
     * @param  iterable<array{subtotal: float|int|string, product?: ?Product}>  $items
     */
    public function vatIncludedInItems(iterable $items): float
    {
        if (! $this->vatActive() || ! $this->vatInclusive()) {
            return 0.0;
        }

        return round(collect($items)->sum(function (array $item): float {
            $rate = $this->vatRateFor($item['product'] ?? null);

            return max(0, (float) $item['subtotal']) * $rate / (100 + $rate);
        }), 2);
    }

    /** Admin-entered delivery time for an area ('inside' / 'outside'), or null when not set. */
    public function deliveryTimeFor(?string $area): ?string
    {
        $value = match ($area) {
            'inside' => $this->delivery_time_inside,
            'outside' => $this->delivery_time_outside,
            default => null,
        };

        return filled($value) ? trim((string) $value) : null;
    }

    public function googleFontsUrl(): ?string
    {
        $queries = collect([$this->typography_heading_font, $this->typography_body_font])
            ->filter(fn ($font): bool => isset(self::FONT_OPTIONS[$font]))
            ->map(fn ($font): ?string => self::FONT_OPTIONS[$font]['query'])
            ->filter()
            ->unique()
            ->values();

        return $queries->isEmpty()
            ? null
            : 'https://fonts.googleapis.com/css2?'.$queries->map(fn (string $query): string => 'family='.$query)->implode('&').'&display=swap';
    }

    public function typographyBaseSize(): int
    {
        return max(14, min(18, (int) ($this->typography_base_size ?: 16)));
    }

    public function typographyHeadingWeight(): int
    {
        return in_array((int) $this->typography_heading_weight, [500, 600, 700, 800], true)
            ? (int) $this->typography_heading_weight
            : 700;
    }

    public function typographyBodyWeight(): int
    {
        return in_array((int) $this->typography_body_weight, [400, 500, 600], true)
            ? (int) $this->typography_body_weight
            : 400;
    }

    public function typographyLineHeight(): float
    {
        return match ($this->typography_line_height) {
            'tight' => 1.4,
            'relaxed' => 1.65,
            default => 1.5,
        };
    }

    public function typographyScale(): string
    {
        return array_key_exists((string) $this->typography_scale, self::TYPOGRAPHY_SCALES)
            ? (string) $this->typography_scale
            : 'balanced';
    }

    public function typographyHeadingTracking(): string
    {
        return match ($this->typography_heading_tracking) {
            'normal' => '0em',
            'wide' => '0.015em',
            default => '-0.025em',
        };
    }

    public function typographyBodyTracking(): string
    {
        return match ($this->typography_body_tracking) {
            'compact' => '-0.005em',
            'relaxed' => '0.01em',
            default => '0em',
        };
    }

    public function typographyContentWidth(): string
    {
        return match ($this->typography_content_width) {
            'narrow' => '62ch',
            'wide' => '78ch',
            default => '70ch',
        };
    }

    public function appearanceContainerWidth(): string
    {
        return match ($this->appearance_container_width) {
            'focused' => '72rem',
            'standard' => '80rem',
            'fluid' => '100%',
            default => '92.5rem',
        };
    }

    public function appearancePageGutter(): string
    {
        return match ($this->appearance_page_gutter) {
            'compact' => 'clamp(0.75rem, 1.5vw, 1.25rem)',
            'spacious' => 'clamp(1.25rem, 3vw, 3rem)',
            default => 'clamp(1rem, 2vw, 2rem)',
        };
    }

    public function appearanceCornerRadii(): array
    {
        return match ($this->appearance_corner_radius) {
            'sharp' => ['sm' => '0.125rem', 'md' => '0.25rem', 'lg' => '0.375rem', 'xl' => '0.5rem', '2xl' => '0.75rem'],
            'rounded' => ['sm' => '0.5rem', 'md' => '0.75rem', 'lg' => '1rem', 'xl' => '1.25rem', '2xl' => '1.5rem'],
            default => ['sm' => '0.25rem', 'md' => '0.375rem', 'lg' => '0.5rem', 'xl' => '0.75rem', '2xl' => '1rem'],
        };
    }

    public function appearanceCardShadow(bool $dark = false): string
    {
        if ($this->appearance_shadow === 'none') {
            return 'none';
        }

        if ($this->appearance_shadow === 'elevated') {
            return $dark
                ? '0 18px 40px -18px rgb(0 0 0 / 0.65)'
                : '0 18px 40px -20px rgb(15 23 42 / 0.28)';
        }

        return $dark
            ? '0 10px 28px -20px rgb(0 0 0 / 0.7)'
            : '0 10px 28px -22px rgb(15 23 42 / 0.24)';
    }

    public function appearanceMotionDuration(): string
    {
        return match ($this->appearance_motion) {
            'none' => '0ms',
            'subtle' => '150ms',
            default => '220ms',
        };
    }

    public function appearanceCardHover(): string
    {
        return array_key_exists((string) $this->appearance_card_hover, self::APPEARANCE_CARD_HOVERS)
            ? (string) $this->appearance_card_hover
            : 'subtle';
    }

    protected function normalizedColor(string $attribute, string $fallback): string
    {
        $color = $this->getAttribute($attribute);

        return is_string($color) && preg_match('/^#[0-9a-f]{6}$/i', $color)
            ? strtoupper($color)
            : $fallback;
    }

    protected function contrastColor(string $color): string
    {
        return self::relativeLuminance($color) > 0.179 ? '#000000' : '#FFFFFF';
    }

    protected static function relativeLuminance(string $color): float
    {
        $channels = [
            hexdec(substr($color, 1, 2)),
            hexdec(substr($color, 3, 2)),
            hexdec(substr($color, 5, 2)),
        ];
        $toLinearColor = static function (int $channel): float {
            $value = $channel / 255;

            return $value <= 0.04045 ? $value / 12.92 : (($value + 0.055) / 1.055) ** 2.4;
        };

        return (0.2126 * $toLinearColor($channels[0]))
            + (0.7152 * $toLinearColor($channels[1]))
            + (0.0722 * $toLinearColor($channels[2]));
    }
}
