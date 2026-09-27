<?php

/*
|--------------------------------------------------------------------------
| Investor import: column / label names
|--------------------------------------------------------------------------
|
| Header names (Excel/CSV) and "Label: value" names (pasted text) that are
| read as each investor field. Matching ignores case, spaces, dots, dashes
| and slashes, so "Father's Name", "father name" and "FATHER-NAME" all match.
| Bangla labels are listed next to the English ones because owners' own
| sheets are often headed in Bangla. These are input-matching data, not
| user-visible text.
|
*/

return [
    'fields' => [
        'name' => ['name', 'investor name', 'full name', 'investor', 'নাম', 'ইনভেস্টরের নাম', 'বিনিয়োগকারীর নাম', 'পূর্ণ নাম'],
        'display_name' => ['display name', 'pseudonym', 'display', 'ছদ্মনাম'],
        'guardian_name' => ['guardian name', 'guardian', "father's name", 'father name', 'fathers name', 'spouse name', 'husband name', 'father / spouse name', 'father or spouse name', 'পিতার নাম', 'বাবার নাম', 'স্বামীর নাম', 'পিতা / স্বামীর নাম', 'অভিভাবকের নাম'],
        'date_of_birth' => ['date of birth', 'dob', 'birth date', 'birthday', 'জন্ম তারিখ', 'জন্মতারিখ'],
        'phone' => ['phone', 'mobile', 'mobile no', 'mobile number', 'phone no', 'phone number', 'contact', 'contact no', 'contact number', 'cell', 'মোবাইল', 'মোবাইল নম্বর', 'মোবাইল নং', 'ফোন', 'ফোন নম্বর', 'ফোন নং'],
        'email' => ['email', 'e-mail', 'email address', 'ইমেইল', 'ই-মেইল'],
        'nid_number' => ['nid', 'nid no', 'nid number', 'nid / passport', 'nid or passport', 'national id', 'passport', 'passport no', 'এনআইডি', 'এনআইডি নম্বর', 'জাতীয় পরিচয়পত্র', 'জাতীয় পরিচয়পত্র নম্বর', 'পাসপোর্ট'],
        'address' => ['address', 'present address', 'permanent address', 'ঠিকানা', 'বর্তমান ঠিকানা', 'স্থায়ী ঠিকানা'],
        'stamp_number' => ['stamp', 'stamp no', 'stamp number', 'স্ট্যাম্প', 'স্ট্যাম্প নম্বর', 'স্ট্যাম্প নং'],
        'cheque_number' => ['cheque', 'cheque no', 'cheque number', 'check no', 'check number', 'চেক', 'চেক নম্বর', 'চেক নং'],
        'is_channel_partner' => ['is channel partner', 'is partner', 'also channel partner', 'চ্যানেল পার্টনার কি না'],
        'channel_partner' => ['channel partner', 'channel partner phone', 'channel partner name', 'referred by', 'reference', 'referrer', 'চ্যানেল পার্টনার', 'রেফারেন্স', 'রেফার করেছেন'],
        'nominee_name' => ['nominee', 'nominee name', 'নমিনি', 'নমিনির নাম'],
        'nominee_nid_or_passport' => ['nominee nid', 'nominee nid / passport', 'nominee nid or passport', 'nominee passport', 'নমিনির এনআইডি', 'নমিনির জাতীয় পরিচয়পত্র'],
        'nominee_phone' => ['nominee phone', 'nominee mobile', 'নমিনির মোবাইল', 'নমিনির ফোন'],
        'nominee_relation' => ['nominee relation', 'relation', 'relationship', 'relation to investor', 'সম্পর্ক', 'নমিনির সম্পর্ক'],
        'nominee_address' => ['nominee address', 'নমিনির ঠিকানা'],
    ],

    // Values read as "yes" for the "is channel partner" column.
    'yes_values' => ['yes', 'y', 'true', '1', 'partner', 'হ্যাঁ', 'হা'],

    // One import may hold at most this many investors.
    'max_rows' => 500,
];
