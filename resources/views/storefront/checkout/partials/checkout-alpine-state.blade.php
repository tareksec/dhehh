{{--
    The Alpine state of the checkout page: delivery area detection from the
    address (display only; the server decides the area from the saved address),
    the live delivery charge and the total. Needs $page (StorefrontCheckoutPage),
    $setting, $subtotal, $vatAmount, $insideDhakaKeywords and $outsideDhakaMarkers.
    Read as x-data="@include('storefront.checkout.partials.checkout-alpine-state')".
--}}
{
    address: {{ Illuminate\Support\Js::from((string) old('address', $page->customer->address ?? '')) }},
    insideDhakaKeywords: {{ Illuminate\Support\Js::from($insideDhakaKeywords) }},
    outsideDhakaMarkers: {{ Illuminate\Support\Js::from($outsideDhakaMarkers) }},
    method: {{ Illuminate\Support\Js::from($page->defaultPaymentMethod) }},
    subtotal: {{ $subtotal }},
    vat: {{ $vatAmount ?? 0 }},
    insideDeliveryTime: {{ Illuminate\Support\Js::from($setting->deliveryTimeFor('inside')) }},
    outsideDeliveryTime: {{ Illuminate\Support\Js::from($setting->deliveryTimeFor('outside')) }},
    insideCharge: {{ $page->insideCharge }},
    outsideCharge: {{ $page->outsideCharge }},
    get area() {
        const normalized = String(this.address ?? '').trim().toLocaleLowerCase();
        if (! normalized) return null;
        if (this.outsideDhakaMarkers.some((marker) => normalized.includes(String(marker).toLocaleLowerCase()))) return 'outside';
        return this.insideDhakaKeywords.some((keyword) => normalized.includes(String(keyword).toLocaleLowerCase())) ? 'inside' : 'outside';
    },
    get deliveryCharge() { return this.area === null ? 0 : (this.area === 'inside' ? this.insideCharge : this.outsideCharge); },
    get deliveryTime() { return this.area === null ? null : (this.area === 'inside' ? this.insideDeliveryTime : this.outsideDeliveryTime); },
    get total() { return this.subtotal + this.vat + this.deliveryCharge; }
}
