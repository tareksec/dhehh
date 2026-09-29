@extends(\App\Support\StorefrontThemeRegistry::layoutView($setting->storefrontTheme()))

@section('content')
    @php
        $heroProduct = $products->first();
        $heroSlide = ($slides ?? collect())->first();
        $heroImageUrl = \App\Support\CompanyMedia::publicUrl($heroSlide?->image ?: $heroProduct?->image, $company);
        $catalogUrl = \App\Support\StorefrontUrl::route('products.index', [], $previewSlug ?? null);
        $contactUrl = \App\Support\StorefrontUrl::route('contact', [], $previewSlug ?? null);
        $categoryUrl = fn ($category) => \App\Support\StorefrontUrl::route('categories.show', [$category->slug], $previewSlug ?? null);
        $productUrl = fn ($product) => \App\Support\StorefrontUrl::route('products.show', [$product->slug], $previewSlug ?? null);
        $featuredProducts = $products->take(6);
        $featuredCategories = $categories->take(4);
        $heroHeading = $setting->hero_heading ?: 'Solar buying, re-engineered.';
        $heroSubheading = $setting->hero_subheading ?: 'Real solar products, ERP-connected availability and project-ready quotation—built for Bangladesh.';
        $heroCta = $setting->hero_cta_label ?: 'Explore Modules';
        $moduleProduct = $featuredProducts->first();
    @endphp

    <div class="noor-solar-home">
        <section class="noor-hero noor-shell" aria-labelledby="noor-hero-title">
            <div class="noor-hero-card">
                <div class="noor-hero-grid" aria-hidden="true"></div>
                <div class="noor-hero-copy" x-reveal>
                    <p class="noor-eyebrow">
                        <span class="noor-status-dot"></span>
                        ERP-connected solar storefront
                    </p>
                    <h1 id="noor-hero-title">{{ $heroHeading }}</h1>
                    <p class="noor-lead">{{ $heroSubheading }}</p>
                    <div class="noor-actions">
                        <a class="noor-button noor-button-gold" href="#noor-products">
                            {{ $heroCta }}
                            <svg aria-hidden="true" viewBox="0 0 24 24"><path d="m5 12 14 0m-6-6 6 6-6 6"/></svg>
                        </a>
                        <a class="noor-button noor-button-outline" href="#noor-builder">
                            Build My System
                            <svg aria-hidden="true" viewBox="0 0 24 24"><path d="M4 4h6v6H4zM14 4h6v6h-6zM4 14h6v6H4zM14 14h6v6h-6z"/></svg>
                        </a>
                    </div>
                    <dl class="noor-hero-stats">
                        <div><dt>Stock signal</dt><dd>ERP Live</dd></div>
                        <div><dt>Technology</dt><dd>N-Type ready</dd></div>
                        <div><dt>Sales path</dt><dd>Retail & B2B</dd></div>
                    </dl>
                </div>

                <div class="noor-hero-visual" x-reveal>
                    <div class="noor-hero-media">
                        @if ($heroImageUrl)
                            <img src="{{ $heroImageUrl }}" alt="{{ $heroProduct?->name ?: $company->name.' solar installation' }}" width="1200" height="900" fetchpriority="high">
                        @else
                            <div class="noor-panel-fallback" role="img" aria-label="Illustrated solar module array">
                                @for ($row = 0; $row < 4; $row++)
                                    @for ($column = 0; $column < 6; $column++)
                                        <span></span>
                                    @endfor
                                @endfor
                            </div>
                        @endif
                    </div>
                    <article class="noor-floating-product">
                        <div class="noor-product-thumb">
                            @if ($heroProduct?->image)
                                <img src="{{ \App\Support\CompanyMedia::publicUrl($heroProduct->image, $company) }}" alt="" width="160" height="200">
                            @else
                                <svg aria-hidden="true" viewBox="0 0 48 64"><rect x="5" y="3" width="38" height="58" rx="2"/><path d="M5 18h38M5 33h38M5 48h38M17 3v58M30 3v58"/></svg>
                            @endif
                        </div>
                        <div class="min-w-0">
                            <div class="noor-badge-row">
                                <span class="noor-stock-badge">{{ ($heroProduct?->stock ?? 0) > 0 ? 'In Stock' : (($heroProduct?->is_preorder ?? false) ? 'Pre-order' : 'Quote Ready') }}</span>
                                <span class="noor-data-label">ERP LIVE</span>
                            </div>
                            <strong>{{ $heroProduct?->name ?: 'N-Type Solar Module' }}</strong>
                            <span>{{ $heroProduct?->sku ?: 'Project & B2B supply' }}</span>
                            <a href="{{ $heroProduct ? $productUrl($heroProduct) : $catalogUrl }}">View technical details</a>
                        </div>
                    </article>
                    <div class="noor-quote-status">
                        <svg aria-hidden="true" viewBox="0 0 24 24"><path d="M13 2 5 14h6l-1 8 8-12h-6z"/></svg>
                        <span><small>System status</small><strong>Ready for quotation</strong></span>
                    </div>
                </div>
            </div>
        </section>

        <section class="noor-capabilities noor-shell" aria-label="Solar capabilities">
            @foreach (['N-Type TOPCon', 'Bifacial Modules', 'Dual Glass', 'ERP Stock', 'Project Supply', 'Bangladesh Support'] as $capability)
                <span><svg aria-hidden="true" viewBox="0 0 20 20"><path d="m5 10 3 3 7-7"/></svg>{{ $capability }}</span>
            @endforeach
        </section>

        <section class="noor-section noor-shell" id="noor-solutions" aria-labelledby="noor-solutions-title">
            <div class="noor-section-heading" x-reveal>
                <div>
                    <p class="noor-kicker">Start with your use-case</p>
                    <h2 id="noor-solutions-title">Find the right solar path faster.</h2>
                </div>
                <p>Choose an application first. We’ll narrow the suitable modules, system capacity and quotation path from there.</p>
            </div>

            <div class="noor-application-grid">
                @forelse ($featuredCategories as $index => $category)
                    <a class="noor-application-card" href="{{ $categoryUrl($category) }}" x-reveal>
                        <div class="noor-application-media">
                            @if ($category->image)
                                <img src="{{ \App\Support\CompanyMedia::publicUrl($category->image, $company) }}" alt="" width="720" height="520" loading="lazy" decoding="async">
                            @else
                                <svg aria-hidden="true" viewBox="0 0 48 48">
                                    @if ($index === 0)
                                        <path d="M6 22 24 8l18 14v19H29V29H19v12H6z"/>
                                    @elseif ($index === 1)
                                        <path d="M8 42V8h25v34M4 42h40M15 15h4m7 0h4m-15 8h4m7 0h4m-15 8h4m7 0h4m7-13h7v24"/>
                                    @elseif ($index === 2)
                                        <path d="M4 42V20l11 7V17l12 7V12h17v30M10 35h5m7 0h5m7 0h5"/>
                                    @else
                                        <path d="M24 5v6m0 26v6M5 24h6m26 0h6M10.6 10.6l4.2 4.2m18.4 18.4 4.2 4.2m0-26.8-4.2 4.2M14.8 33.2l-4.2 4.2M33 24a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z"/>
                                    @endif
                                </svg>
                            @endif
                        </div>
                        <span class="noor-card-index">0{{ $index + 1 }}</span>
                        <div>
                            <h3>{{ $category->name }}</h3>
                            <p>Explore available products and project-ready options for this application.</p>
                            <span class="noor-text-link">Explore solution <svg aria-hidden="true" viewBox="0 0 20 20"><path d="m4 10 12 0m-5-5 5 5-5 5"/></svg></span>
                        </div>
                    </a>
                @empty
                    @foreach ([['Home Solar', 'Backup, hybrid systems and electricity-cost reduction for modern homes.'], ['Office Solar', 'Commercial rooftops and reliable business energy systems.'], ['Factory & Industrial', 'High-capacity modules and engineering-led project quotation.'], ['Project Supply', 'Bulk procurement and project-specific commercial support.']] as $index => [$title, $copy])
                        <a class="noor-application-card" href="{{ $catalogUrl }}" x-reveal>
                            <div class="noor-application-media noor-application-media-symbol">
                                <svg aria-hidden="true" viewBox="0 0 48 48"><path d="M7 38 15 14h22l5 24H7Zm4-8h27M17 14l-4 24m18-24 4 24M14 22h25"/></svg>
                            </div>
                            <span class="noor-card-index">0{{ $index + 1 }}</span>
                            <div><h3>{{ $title }}</h3><p>{{ $copy }}</p><span class="noor-text-link">Explore solution <svg aria-hidden="true" viewBox="0 0 20 20"><path d="m4 10 12 0m-5-5 5 5-5 5"/></svg></span></div>
                        </a>
                    @endforeach
                @endforelse
            </div>
        </section>

        <section class="noor-section noor-shell" id="noor-products" aria-labelledby="noor-products-title">
            <div class="noor-section-heading" x-reveal>
                <div><p class="noor-kicker">Featured solar products</p><h2 id="noor-products-title">Real products. Cleaner decisions.</h2></div>
                <a class="noor-heading-link" href="{{ $catalogUrl }}">View Full Catalog <svg aria-hidden="true" viewBox="0 0 20 20"><path d="m4 10 12 0m-5-5 5 5-5 5"/></svg></a>
            </div>

            @if ($featuredProducts->isNotEmpty())
                <div class="noor-product-rail">
                    @foreach ($featuredProducts as $product)
                        @php
                            $inStock = $product->stock > 0;
                            $status = $inStock ? ($product->stock <= 5 ? 'Low Stock' : 'In Stock') : ($product->is_preorder ? 'Pre-order' : 'Project Quote');
                        @endphp
                        <article class="noor-product-card" x-reveal>
                            <a class="noor-product-media" href="{{ $productUrl($product) }}">
                                @if ($product->image)
                                    <img src="{{ \App\Support\CompanyMedia::publicUrl($product->image, $company) }}" alt="{{ $product->name }}" width="720" height="720" loading="lazy" decoding="async">
                                @else
                                    <svg aria-hidden="true" viewBox="0 0 90 120"><rect x="7" y="5" width="76" height="110" rx="3"/><path d="M7 27h76M7 49h76M7 71h76M7 93h76M26 5v110M45 5v110M64 5v110"/></svg>
                                @endif
                            </a>
                            <div class="noor-product-copy">
                                <div class="noor-badge-row">
                                    <span class="noor-stock-badge {{ ! $inStock ? 'is-quote' : '' }}">{{ $status }}</span>
                                    <span class="noor-data-label">ERP LIVE</span>
                                </div>
                                <p>{{ $product->category?->name ?: 'Solar Module' }}</p>
                                <h3><a href="{{ $productUrl($product) }}">{{ $product->name }}</a></h3>
                                <dl>
                                    <div><dt>SKU</dt><dd>{{ $product->sku }}</dd></div>
                                    <div><dt>Supply</dt><dd>{{ $product->effectiveMoq() > 1 ? 'MOQ '.$product->effectiveMoq() : 'Retail / Project' }}</dd></div>
                                </dl>
                                <div class="noor-product-price">
                                    @if ((float) $product->selling_price > 0)
                                        <span>{{ \App\Support\MoneyFormatter::currency((float) $product->selling_price) }}</span>
                                    @else
                                        <span>Request Pricing</span>
                                    @endif
                                    <a href="{{ $productUrl($product) }}" aria-label="View {{ $product->name }}">
                                        <svg aria-hidden="true" viewBox="0 0 20 20"><path d="m4 10 12 0m-5-5 5 5-5 5"/></svg>
                                    </a>
                                </div>
                            </div>
                        </article>
                    @endforeach
                </div>
            @else
                <div class="noor-empty-state" role="status">
                    <svg aria-hidden="true" viewBox="0 0 48 48"><path d="M7 38 15 14h22l5 24H7Zm4-8h27M17 14l-4 24m18-24 4 24M14 22h25"/></svg>
                    <h3>Solar products are being prepared.</h3>
                    <p>Contact the sales team for current module availability and project pricing.</p>
                    <a class="noor-button noor-button-dark" href="{{ $contactUrl }}">Contact Sales</a>
                </div>
            @endif
        </section>

        <section class="noor-section noor-shell" id="noor-technology" aria-labelledby="noor-technology-title">
            <div class="noor-technology-panel" x-reveal>
                <div class="noor-technology-intro">
                    <p class="noor-kicker noor-kicker-gold">Manufacturing intelligence</p>
                    <h2 id="noor-technology-title">Engineering depth behind every module.</h2>
                    <p>Technical information should help buyers compare products confidently—without burying essential decisions inside a datasheet.</p>
                    <div class="noor-trace-card"><small>ERP trust layer</small><strong>SKU-level documents & traceability</strong><span>Publish only the certifications verified for the selected product and market.</span></div>
                </div>
                <div class="noor-technology-grid">
                    @foreach ([
                        ['Solar Cells', 'Clear technology and module-family information for confident comparison.', 'sun'],
                        ['High-conversion Modules', 'Product-level construction, performance and warranty context.', 'module'],
                        ['Energy Storage', 'Expandable storage options aligned to household and commercial needs.', 'battery'],
                        ['EPC Turnkey', 'A direct path from requirement discovery to project quotation.', 'project'],
                    ] as [$title, $copy, $icon])
                        <article>
                            <span class="noor-icon-box">
                                @if ($icon === 'sun')
                                    <svg aria-hidden="true" viewBox="0 0 24 24"><circle cx="12" cy="12" r="4"/><path d="M12 2v2m0 16v2M2 12h2m16 0h2M4.9 4.9l1.4 1.4m11.4 11.4 1.4 1.4m0-14.2-1.4 1.4M6.3 17.7l-1.4 1.4"/></svg>
                                @elseif ($icon === 'module')
                                    <svg aria-hidden="true" viewBox="0 0 24 24"><rect x="3" y="3" width="18" height="18" rx="2"/><path d="M3 9h18M3 15h18M9 3v18m6-18v18"/></svg>
                                @elseif ($icon === 'battery')
                                    <svg aria-hidden="true" viewBox="0 0 24 24"><rect x="4" y="6" width="16" height="13" rx="2"/><path d="M9 3h6v3m-4 4-2 4h3l-1 3 4-5h-3l1-2"/></svg>
                                @else
                                    <svg aria-hidden="true" viewBox="0 0 24 24"><path d="M4 20V9l6 4V7l6 4V4h4v16H4Z"/><path d="M8 17h2m3 0h2m3 0h2"/></svg>
                                @endif
                            </span>
                            <h3>{{ $title }}</h3><p>{{ $copy }}</p>
                        </article>
                    @endforeach
                </div>
            </div>
        </section>

        <section class="noor-section noor-shell" id="noor-module-lab" aria-labelledby="noor-module-lab-title">
            <div class="noor-section-heading" x-reveal>
                <div><p class="noor-kicker">Interactive product experience</p><h2 id="noor-module-lab-title">Inspect the module before the datasheet.</h2></div>
                <p>The viewer loads only near the viewport. Clear buttons provide an accessible alternative to pointer rotation.</p>
            </div>
            <div class="noor-module-lab" data-noor-module-lab x-reveal>
                <div class="noor-module-stage" data-noor-module-stage role="img" aria-label="Interactive three-dimensional solar module preview">
                    <div class="noor-stage-fallback" data-noor-stage-fallback aria-hidden="true">
                        <div class="noor-stage-panel">@for ($cell = 0; $cell < 72; $cell++)<span></span>@endfor</div>
                    </div>
                    <div class="noor-stage-label"><span class="noor-status-dot"></span> Interactive module</div>
                    <p class="noor-stage-help">Drag to rotate. Use the view controls for precise navigation.</p>
                </div>
                <aside class="noor-module-details">
                    <div class="noor-badge-row"><span class="noor-stock-badge">ERP LIVE</span><span class="noor-tech-badge">N-TYPE READY</span></div>
                    <h3>{{ $moduleProduct?->name ?: 'Interactive Solar Module' }}</h3>
                    <p>{{ $moduleProduct?->sku ?: 'Product model can be mapped to the ERP SKU.' }}</p>
                    <fieldset class="noor-view-controls">
                        <legend>3D View</legend>
                        <div>
                            <button type="button" data-noor-module-view="front" aria-pressed="true">Front</button>
                            <button type="button" data-noor-module-view="cells" aria-pressed="false">Cells</button>
                            <button type="button" data-noor-module-view="back" aria-pressed="false">Back</button>
                        </div>
                    </fieldset>
                    <dl class="noor-module-specs">
                        <div><dt>Availability</dt><dd>{{ ($moduleProduct?->stock ?? 0) > 0 ? 'In Stock' : 'Project Supply' }}</dd></div>
                        <div><dt>Pricing mode</dt><dd>{{ ($moduleProduct?->selling_price ?? 0) > 0 ? 'Retail / Quote' : 'ERP Quote' }}</dd></div>
                        <div><dt>Application</dt><dd>Commercial / Project</dd></div>
                        <div><dt>Data source</dt><dd>ERP Product</dd></div>
                    </dl>
                    <div class="noor-module-actions">
                        <a class="noor-button noor-button-dark" href="{{ $moduleProduct ? $productUrl($moduleProduct) : $catalogUrl }}">View Product Details</a>
                        <a class="noor-button noor-button-subtle" href="{{ $contactUrl }}">Request Project Quote</a>
                    </div>
                    <p class="noor-module-note" data-noor-module-status aria-live="polite">3D preview will load when this section enters the viewport.</p>
                </aside>
            </div>
        </section>

        <section class="noor-section noor-shell" id="noor-builder" aria-labelledby="noor-builder-title">
            <div class="noor-builder" x-data="{ application: 'Home', capacity: '1–3 kW', goal: 'Reduce electricity bill' }" x-reveal>
                <div class="noor-builder-copy">
                    <p class="noor-kicker noor-kicker-gold">Solar system builder</p>
                    <h2 id="noor-builder-title">Turn your requirement into a clearer project brief.</h2>
                    <p>Choose three essentials. The sales team can use this brief to continue the technical discussion and quotation.</p>
                    <div class="noor-builder-summary" aria-live="polite">
                        <small>Your starting brief</small>
                        <strong x-text="application + ' · ' + capacity"></strong>
                        <span x-text="goal"></span>
                    </div>
                </div>
                <div class="noor-builder-form">
                    <fieldset>
                        <legend><span>01</span> Application</legend>
                        <div class="noor-choice-grid">
                            @foreach (['Home', 'Office', 'Factory', 'EPC Project'] as $choice)
                                <button type="button" @click="application = '{{ $choice }}'" :aria-pressed="(application === '{{ $choice }}').toString()" :class="application === '{{ $choice }}' ? 'is-selected' : ''">{{ $choice }}</button>
                            @endforeach
                        </div>
                    </fieldset>
                    <fieldset>
                        <legend><span>02</span> Capacity</legend>
                        <div class="noor-choice-grid">
                            @foreach (['1–3 kW', '3–10 kW', '10–50 kW', '100 kW+'] as $choice)
                                <button type="button" @click="capacity = '{{ $choice }}'" :aria-pressed="(capacity === '{{ $choice }}').toString()" :class="capacity === '{{ $choice }}' ? 'is-selected' : ''">{{ $choice }}</button>
                            @endforeach
                        </div>
                    </fieldset>
                    <fieldset>
                        <legend><span>03</span> Goal</legend>
                        <div class="noor-choice-grid">
                            @foreach (['Backup', 'Reduce electricity bill', 'Hybrid', 'Full solar'] as $choice)
                                <button type="button" @click="goal = '{{ $choice }}'" :aria-pressed="(goal === '{{ $choice }}').toString()" :class="goal === '{{ $choice }}' ? 'is-selected' : ''">{{ $choice }}</button>
                            @endforeach
                        </div>
                    </fieldset>
                    <a class="noor-button noor-button-gold" href="{{ $contactUrl }}">Continue With Sales <svg aria-hidden="true" viewBox="0 0 24 24"><path d="m5 12 14 0m-6-6 6 6-6 6"/></svg></a>
                </div>
            </div>
        </section>

        <section class="noor-section noor-shell" aria-labelledby="noor-trust-title">
            <div class="noor-local-trust" x-reveal>
                <div>
                    <p class="noor-kicker">Manufacturer-backed, locally delivered</p>
                    <h2 id="noor-trust-title">Global manufacturing. Local execution.</h2>
                    <p>{{ $company->name }} brings product discovery, Bangladesh inventory, consultation, quotations and after-sales coordination into one storefront.</p>
                </div>
                <dl>
                    <div><dt>Inventory</dt><dd>ERP-connected status</dd></div>
                    <div><dt>Documents</dt><dd>Verified per SKU</dd></div>
                    <div><dt>Projects</dt><dd>Engineering-led quote</dd></div>
                    <div><dt>Support</dt><dd>Bangladesh team</dd></div>
                </dl>
            </div>
        </section>

        <section class="noor-cta noor-shell" aria-labelledby="noor-cta-title" x-reveal>
            <div>
                <p class="noor-kicker noor-kicker-gold">Project quotation</p>
                <h2 id="noor-cta-title">Tell us what you need. We’ll shape the right solar path.</h2>
                <p>Home, office, factory or EPC supply—start with a clear requirement and continue with the Noor sales team.</p>
            </div>
            <div class="noor-actions">
                <a class="noor-button noor-button-gold" href="#noor-builder">Build My System</a>
                <a class="noor-button noor-button-outline" href="{{ $contactUrl }}">Request Project Quote</a>
            </div>
        </section>

        <a class="noor-mobile-quote" href="{{ $contactUrl }}">
            <svg aria-hidden="true" viewBox="0 0 24 24"><path d="M8 10h8M8 14h5M5 4h14a2 2 0 0 1 2 2v10a2 2 0 0 1-2 2h-7l-5 3v-3H5a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2Z"/></svg>
            Get a Quote
        </a>
    </div>
@endsection
