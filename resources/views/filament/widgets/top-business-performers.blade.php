@php
    $productMax = max((float) ($products->max('revenue') ?? 0), 1);
    $customerMax = max((float) ($customers->max('total_sales') ?? 0), 1);
    $supplierMax = max((float) ($suppliers->max('total_purchases') ?? 0), 1);

    $groups = [
        [
            'title' => 'Top Products',
            'subtitle' => 'Units sold and revenue',
            'icon' => 'heroicon-o-cube',
            'accent' => 'emerald',
            'items' => $products,
            'max' => $productMax,
            'empty' => 'No product sales yet.',
            'name' => fn ($product) => $product->name,
            'meta' => fn ($product) => number_format((float) $product->quantity) . ' units',
            'value' => fn ($product) => (float) $product->revenue,
            'trail' => fn ($product) => 'Revenue',
        ],
        [
            'title' => 'Top Customers',
            'subtitle' => 'Sales volume and dues',
            'icon' => 'heroicon-o-user-group',
            'accent' => 'sky',
            'items' => $customers,
            'max' => $customerMax,
            'empty' => 'No customer sales yet.',
            'name' => fn ($customer) => $customer->name,
            'meta' => fn ($customer) => 'Due '.\App\Support\MoneyFormatter::currency((float) $customer->current_balance),
            'value' => fn ($customer) => (float) $customer->total_sales,
            'trail' => fn ($customer) => 'Sales',
        ],
        [
            'title' => 'Top Suppliers',
            'subtitle' => 'Purchase volume and payable',
            'icon' => 'heroicon-o-building-storefront',
            'accent' => 'amber',
            'items' => $suppliers,
            'max' => $supplierMax,
            'empty' => 'No supplier purchases yet.',
            'name' => fn ($supplier) => $supplier->name,
            'meta' => fn ($supplier) => 'Payable '.\App\Support\MoneyFormatter::currency((float) $supplier->current_balance),
            'value' => fn ($supplier) => (float) $supplier->total_purchases,
            'trail' => fn ($supplier) => 'Purchases',
        ],
    ];
@endphp

<x-filament-widgets::widget>
    <style>
        .zz-performers {
            --zz-performer-card-bg: #ffffff;
            --zz-performer-card-border: #e5e7eb;
            --zz-performer-card-shadow: 0 16px 36px rgb(15 23 42 / 0.08);
            --zz-performer-head-bg: #f8fafc;
            --zz-performer-head-border: #e5e7eb;
            --zz-performer-title: #111827;
            --zz-performer-muted: #64748b;
            --zz-performer-row-bg: #ffffff;
            --zz-performer-row-border: #e5e7eb;
            --zz-performer-rank-bg: #f8fafc;
            --zz-performer-rank-border: #e2e8f0;
            --zz-performer-rank-text: #111827;
            --zz-performer-bar-bg: #e5e7eb;
            --zz-performer-empty-bg: #f8fafc;
            --zz-performer-empty-border: #cbd5e1;
            --zz-performer-icon-emerald-text: #047857;
            --zz-performer-icon-emerald-bg: #ecfdf5;
            --zz-performer-icon-emerald-border: #a7f3d0;
            --zz-performer-icon-sky-text: #0369a1;
            --zz-performer-icon-sky-bg: #eff6ff;
            --zz-performer-icon-sky-border: #bfdbfe;
            --zz-performer-icon-amber-text: #b45309;
            --zz-performer-icon-amber-bg: #fffbeb;
            --zz-performer-icon-amber-border: #fde68a;
            display: grid;
            gap: 16px;
        }

        .dark .zz-performers {
            --zz-performer-card-bg: #17181d;
            --zz-performer-card-border: #2b2d34;
            --zz-performer-card-shadow: 0 18px 42px rgb(0 0 0 / 0.18);
            --zz-performer-head-bg: #111827;
            --zz-performer-head-border: #2b3445;
            --zz-performer-title: #f8fafc;
            --zz-performer-muted: #9ca3af;
            --zz-performer-row-bg: #202127;
            --zz-performer-row-border: #30333b;
            --zz-performer-rank-bg: #2b2d34;
            --zz-performer-rank-border: #3b3f49;
            --zz-performer-rank-text: #f8fafc;
            --zz-performer-bar-bg: #111318;
            --zz-performer-empty-bg: #202127;
            --zz-performer-empty-border: #3a3d45;
            --zz-performer-icon-emerald-text: #6ee7b7;
            --zz-performer-icon-emerald-bg: #063c2c;
            --zz-performer-icon-emerald-border: #0f6b4d;
            --zz-performer-icon-sky-text: #93c5fd;
            --zz-performer-icon-sky-bg: #172b4d;
            --zz-performer-icon-sky-border: #254a7d;
            --zz-performer-icon-amber-text: #fcd34d;
            --zz-performer-icon-amber-bg: #46320b;
            --zz-performer-icon-amber-border: #79570e;
        }

        .zz-performers__grid {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 16px;
        }

        .zz-performer-card {
            overflow: hidden;
            min-width: 0;
            background: var(--zz-performer-card-bg);
            border: 1px solid var(--zz-performer-card-border);
            border-radius: 12px;
            box-shadow: var(--zz-performer-card-shadow);
        }

        .zz-performer-card__head {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 16px;
            background: var(--zz-performer-head-bg);
            border-bottom: 1px solid var(--zz-performer-head-border);
        }

        .zz-performer-icon {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 42px;
            height: 42px;
            flex: 0 0 auto;
            border-radius: 10px;
        }

        .zz-performer-icon svg {
            width: 21px;
            height: 21px;
        }

        .zz-performer-icon--emerald {
            color: var(--zz-performer-icon-emerald-text);
            background: var(--zz-performer-icon-emerald-bg);
            border: 1px solid var(--zz-performer-icon-emerald-border);
        }

        .zz-performer-icon--sky {
            color: var(--zz-performer-icon-sky-text);
            background: var(--zz-performer-icon-sky-bg);
            border: 1px solid var(--zz-performer-icon-sky-border);
        }

        .zz-performer-icon--amber {
            color: var(--zz-performer-icon-amber-text);
            background: var(--zz-performer-icon-amber-bg);
            border: 1px solid var(--zz-performer-icon-amber-border);
        }

        .zz-performer-title {
            margin: 0;
            color: var(--zz-performer-title);
            font-size: 15px;
            font-weight: 850;
            line-height: 1.25;
        }

        .zz-performer-subtitle {
            margin-top: 3px;
            color: var(--zz-performer-muted);
            font-size: 12px;
            line-height: 1.35;
        }

        .zz-performer-list {
            display: grid;
            gap: 10px;
            padding: 14px;
        }

        .zz-performer-row {
            display: grid;
            grid-template-columns: 34px minmax(0, 1fr) auto;
            gap: 10px;
            align-items: center;
            min-height: 70px;
            padding: 10px;
            background: var(--zz-performer-row-bg);
            border: 1px solid var(--zz-performer-row-border);
            border-radius: 10px;
        }

        .zz-performer-rank {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 30px;
            height: 30px;
            color: var(--zz-performer-rank-text);
            background: var(--zz-performer-rank-bg);
            border: 1px solid var(--zz-performer-rank-border);
            border-radius: 9px;
            font-size: 12px;
            font-weight: 850;
            font-variant-numeric: tabular-nums;
        }

        .zz-performer-name {
            overflow: hidden;
            color: var(--zz-performer-title);
            font-size: 13px;
            font-weight: 800;
            line-height: 1.3;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .zz-performer-meta {
            margin-top: 3px;
            color: var(--zz-performer-muted);
            font-size: 12px;
            line-height: 1.3;
        }

        .zz-performer-bar {
            overflow: hidden;
            height: 5px;
            margin-top: 8px;
            background: var(--zz-performer-bar-bg);
            border-radius: 999px;
        }

        .zz-performer-bar span {
            display: block;
            height: 100%;
            min-width: 6px;
            border-radius: inherit;
        }

        .zz-performer-bar--emerald span {
            background: #22c55e;
        }

        .zz-performer-bar--sky span {
            background: #38bdf8;
        }

        .zz-performer-bar--amber span {
            background: #f59e0b;
        }

        .zz-performer-value {
            min-width: 112px;
            text-align: right;
        }

        .zz-performer-value strong {
            display: block;
            color: var(--zz-performer-title);
            font-size: 13px;
            font-weight: 850;
            font-variant-numeric: tabular-nums;
            line-height: 1.25;
            white-space: nowrap;
        }

        .zz-performer-value span {
            display: block;
            margin-top: 3px;
            color: var(--zz-performer-muted);
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
        }

        .zz-performer-empty {
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 120px;
            color: var(--zz-performer-muted);
            background: var(--zz-performer-empty-bg);
            border: 1px dashed var(--zz-performer-empty-border);
            border-radius: 10px;
            font-size: 13px;
            font-weight: 700;
            text-align: center;
        }

        @media (max-width: 1280px) {
            .zz-performers__grid {
                grid-template-columns: 1fr;
            }
        }

        /*
         * Phones: the card used to sit inside the section, with a padded list
         * inside the card and a bordered box for every row — four boxes inside
         * one another, so the names had little room and each row was tall. Now
         * the section's own padding is smaller, the card has no shadow, rows are
         * a plain divided list (no box), and the amount sits to the right of the
         * name instead of on a line of its own.
         */
        @media (max-width: 640px) {
            .fi-section.zz-performers-section:not(.fi-section-not-contained):not(.fi-divided) > .fi-section-content-ctn > .fi-section-content {
                padding: 12px;
            }

            .zz-performers,
            .zz-performers__grid {
                gap: 12px;
            }

            .zz-performer-card {
                border-radius: 10px;
                box-shadow: none;
            }

            .zz-performer-card__head {
                gap: 10px;
                padding: 12px;
            }

            .zz-performer-icon {
                width: 36px;
                height: 36px;
            }

            .zz-performer-list {
                gap: 0;
                padding: 0;
            }

            .zz-performer-row {
                grid-template-columns: 28px minmax(0, 1fr) auto;
                gap: 10px;
                align-items: start;
                min-height: 0;
                padding: 12px;
                background: transparent;
                border: 0;
                border-radius: 0;
            }

            .zz-performer-row + .zz-performer-row {
                border-top: 1px solid var(--zz-performer-row-border);
            }

            .zz-performer-rank {
                width: 28px;
                height: 28px;
            }

            .zz-performer-name {
                display: -webkit-box;
                -webkit-box-orient: vertical;
                -webkit-line-clamp: 2;
                white-space: normal;
                overflow-wrap: anywhere;
            }

            /* One width for every amount, so the bars under the names end together. */
            .zz-performer-value {
                min-width: 100px;
                text-align: right;
            }

            .zz-performer-empty {
                min-height: 96px;
                margin: 12px;
            }
        }
    </style>

    <x-filament::section class="zz-performers-section">
        <x-slot name="heading">Top Business Performers</x-slot>
        <x-slot name="description">Best products, customers, and suppliers by confirmed business activity.</x-slot>

        <div class="zz-performers">
            <div class="zz-performers__grid">
                @foreach ($groups as $group)
                    <section class="zz-performer-card">
                        <div class="zz-performer-card__head">
                            <span class="zz-performer-icon zz-performer-icon--{{ $group['accent'] }}">
                                <x-filament::icon :icon="$group['icon']" />
                            </span>
                            <div>
                                <h3 class="zz-performer-title">{{ $group['title'] }}</h3>
                                <div class="zz-performer-subtitle">{{ $group['subtitle'] }}</div>
                            </div>
                        </div>

                        <div class="zz-performer-list">
                            @forelse ($group['items'] as $item)
                                @php
                                    $value = $group['value']($item);
                                    $width = max(6, min(100, ($value / $group['max']) * 100));
                                @endphp

                                <div class="zz-performer-row">
                                    <span class="zz-performer-rank">{{ $loop->iteration }}</span>
                                    <div class="min-w-0">
                                        <div class="zz-performer-name" title="{{ $group['name']($item) }}">{{ $group['name']($item) }}</div>
                                        <div class="zz-performer-meta">{{ $group['meta']($item) }}</div>
                                        <div class="zz-performer-bar zz-performer-bar--{{ $group['accent'] }}">
                                            <span style="width: {{ $width }}%"></span>
                                        </div>
                                    </div>
                                    <div class="zz-performer-value">
                                        <strong>{{ \App\Support\MoneyFormatter::currency($value) }}</strong>
                                        <span>{{ $group['trail']($item) }}</span>
                                    </div>
                                </div>
                            @empty
                                <div class="zz-performer-empty">{{ $group['empty'] }}</div>
                            @endforelse
                        </div>
                    </section>
                @endforeach
            </div>
        </div>
    </x-filament::section>
</x-filament-widgets::widget>
