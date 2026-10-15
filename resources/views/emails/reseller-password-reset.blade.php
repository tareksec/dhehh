{!! __('The :company team sent you this link so you can choose a new password for your reseller account.', ['company' => $companyName]) !!}

{!! $url !!}

{!! __('The link works once and expires in :minutes minutes. If you did not expect this email, ignore it: your password stays the same.', ['minutes' => $minutes]) !!}
