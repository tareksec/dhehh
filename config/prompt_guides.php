<?php

/*
|--------------------------------------------------------------------------
| Prompt Enhancer guidance
|--------------------------------------------------------------------------
|
| Hard-coded, expert-written defaults for the shared Prompt Enhancer
| (App\Services\PromptEnhancement\PromptEnhancementService). An admin can
| override any `contexts` entry per company from Settings → Integrations → Prompt Enhancer
| without a code change; that override is stored at
| `companies.settings->prompt_guides->{key}` and takes precedence over the
| value here (see App\Services\PromptEnhancement\PromptGuideRepository).
|
| `contexts` keys are "{tool}.{context}" so the same file serves every tool
| (image_generation.*, video_generation.*). `provider_styles` keys match an
| image provider profile's `api_format` (openai | google | stability |
| custom) and `video_provider_styles` a video provider profile's — the
| enhancer adapts its phrasing to whichever model the user picked downstream.
|
*/

return [

    'contexts' => [

        'image_generation.product_photo' => <<<'TXT'
        You are rewriting a prompt for an AI image model that will produce a COMMERCIAL PRODUCT PHOTO for an e-commerce catalog.
        Keep the user's product and intent exactly. Improve the prompt by making these explicit when the user did not:
        - the product as the clear single subject, centered, full product in frame, not cropped
        - a clean, uncluttered surface and background (seamless studio sweep, or a simple relevant surface)
        - soft, even lighting with a gentle natural shadow for grounding; avoid harsh flash and blown highlights
        - realistic material, texture, and colour accuracy — the product must look true to life
        - a straight-on or slight three-quarter angle at eye level
        - sharp focus on the product, high detail, photographic realism
        Never add people, hands, text, logos, watermarks, or busy props unless the user asked for them. Do not invent brand names.
        TXT,

        'image_generation.ad_creative' => <<<'TXT'
        You are rewriting a prompt for an AI image model that will produce a SOCIAL / PAID AD CREATIVE (Facebook / Instagram).
        Keep the user's product, offer, and intent. Improve the prompt by making these explicit when the user did not:
        - one bold focal subject that reads instantly at small size on a phone
        - a lifestyle or in-use context that shows the benefit, or a striking studio look with strong colour contrast
        - deliberate negative space on one side or the top where ad copy can later be placed
        - confident, vibrant, on-brand colour; good contrast; scroll-stopping composition
        - natural, believable lighting — not obviously fake or over-rendered
        Do NOT render headline text, prices, badges, call-to-action buttons, or logos inside the image — those are added later by the ads tool. Keep it authentic, not deceptive.
        TXT,

        'image_generation.landing_banner' => <<<'TXT'
        You are rewriting a prompt for an AI image model that will produce a WIDE HERO / LANDING-PAGE BANNER for an offer page.
        Keep the user's product, theme, and intent. Improve the prompt by making these explicit when the user did not:
        - a wide, horizontal composition with the subject set to one side
        - generous clean space on the opposite side for a headline and button
        - a cohesive colour palette that suits a premium storefront; soft depth, gentle gradient or bokeh background acceptable
        - even lighting, no distracting clutter, nothing important near the extreme edges (they get cropped on mobile)
        - photographic realism unless the user asked for an illustrated or graphic style
        Do not render any text, price, or button in the image.
        TXT,

        'image_generation.video_reference' => <<<'TXT'
        You are rewriting a prompt for an AI image model whose output will be used as a STYLE / REFERENCE FRAME for a later AI video.
        Keep the user's subject and intent. Improve the prompt by making these explicit when the user did not:
        - a clear, well-lit establishing view of the subject with obvious form, materials, and colour
        - a neutral, consistent environment and lighting that would hold up across many frames
        - a stable, centered composition with headroom and space around the subject for motion
        - high detail and realism so downstream motion has something solid to work from
        Avoid motion blur, extreme perspective, heavy vignettes, or anything that only works as a single still.
        TXT,

        'image_generation.general' => <<<'TXT'
        You are rewriting a prompt for a general-purpose AI image model.
        Keep the user's subject and intent exactly — never change what they asked for. Improve the prompt by adding, only where the user was vague:
        - a clear main subject and what it is doing
        - setting / background, time of day, and lighting
        - composition and camera angle
        - style (photo, illustration, 3D render, …), mood, and colour palette
        - a few concrete detail and quality cues
        Keep it to two or three tight sentences. Do not pad with empty adjectives, and do not add text, logos, or watermarks unless asked.
        TXT,

        'video_generation.product_demo' => <<<'TXT'
        You are rewriting a prompt for an AI video model that animates a STARTING PRODUCT IMAGE into a short PRODUCT DEMO clip (a few seconds).
        The starting image already fixes the product, setting, and colours — describe MOTION, not a new scene. Improve the prompt by making these explicit when the user did not:
        - one slow, smooth camera move (gentle push-in, slow orbit, or subtle parallax) — never several moves in one clip
        - small, realistic motion that shows the product off: light gliding across the surface, a slow turn, fabric settling, steam or a soft reflection
        - the product stays sharp, centered, true to its real shape and colour, and fully in frame from first to last frame
        - calm, steady pacing with no cuts, flashes, or sudden changes
        Never morph, warp, or change the product, and never add people, hands, text, logos, or watermarks unless the user asked for them.
        TXT,

        'video_generation.ad_creative' => <<<'TXT'
        You are rewriting a prompt for an AI video model that animates a STARTING IMAGE into a short SOCIAL / PAID AD clip (Facebook / Instagram, a few seconds).
        The starting image already fixes the subject and look — describe MOTION and energy, not a new scene. Improve the prompt by making these explicit when the user did not:
        - movement that grabs attention in the first second: a confident push-in, a quick reveal, or lively in-scene motion
        - one clear focal subject that stays readable on a small phone screen throughout
        - vibrant but believable lighting and colour; motion that feels natural, not glitchy or over-rendered
        - space kept clear (usually top or one side) where ad copy can be added later
        Do NOT render headline text, prices, badges, buttons, or logos in the video — the ads tool adds those. Keep it authentic, not deceptive.
        TXT,

        'video_generation.landing_hero' => <<<'TXT'
        You are rewriting a prompt for an AI video model that animates a STARTING IMAGE into a short, LOOPABLE HERO BACKGROUND clip for a landing / offer page.
        The starting image already fixes the subject and look — describe subtle MOTION, not a new scene. Improve the prompt by making these explicit when the user did not:
        - very gentle, slow ambient motion (drifting light, soft bokeh, a slight camera drift) that loops smoothly
        - the subject stays in the same place so text can sit over the calm side of the frame
        - no fast moves, cuts, or flashing — it must not distract from the headline and button
        - consistent lighting and colour across the whole clip
        Do not render any text, price, or button in the video.
        TXT,

        'video_generation.general' => <<<'TXT'
        You are rewriting a prompt for an AI video model that animates a STARTING IMAGE into a short clip (a few seconds).
        Keep the user's subject and intent exactly. The starting image already sets the scene, so improve the prompt by describing, only where the user was vague:
        - the camera movement (static, slow push-in, pan, orbit) — one move, not several
        - what moves in the scene and how (speed, direction, a natural physical detail)
        - lighting and atmosphere, kept consistent with the starting image
        - the pace and mood of the clip
        Keep it to two or three tight sentences. Do not describe a different scene from the image, and do not add text, logos, or watermarks unless asked.
        TXT,

    ],

    'provider_styles' => [

        'openai' => 'Target model: OpenAI (gpt-image-1 / DALL·E). Write the enhanced prompt as one to three natural, descriptive sentences in plain English. Do not use comma-separated keyword lists, weights, or "--" flags.',

        'google' => 'Target model: Google Imagen. Write natural descriptive language with strong photographic detail — lens feel, lighting quality, texture and material. Avoid keyword-tag lists and negative-prompt syntax.',

        'stability' => 'Target model: Stability AI. Prefer a compact, comma-separated stack of vivid descriptive phrases and quality tags (subject first, then setting, lighting, style, then quality terms). Front-load the most important elements.',

        'custom' => 'Write the enhanced prompt as clear natural descriptive language, one to three sentences.',

    ],

    // Style notes for the Video Generation tool's providers. Kept apart from
    // `provider_styles`, whose entries describe the image models (Stability's
    // image keyword-tag style does not suit a video motion prompt). Stability
    // image-to-video has no entry: it never receives the prompt.
    'video_provider_styles' => [

        'custom' => 'Target: a text-and-image-to-video model. Write the enhanced prompt as one to three natural sentences: the camera movement first, then what moves in the scene, then lighting, atmosphere, and pace. No keyword lists, weights, or "--" flags.',

    ],

];
