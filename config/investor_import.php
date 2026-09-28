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
        'name' => ['name', 'investor name', 'investors name', 'full name', 'investor', 'নাম', 'ইনভেস্টরের নাম', 'ইনভেস্টরদের নাম', 'ইনভেস্টর', 'বিনিয়োগকারীর নাম', 'বিনিয়োগকারীদের নাম', 'পূর্ণ নাম'],
        'display_name' => ['display name', 'pseudonym', 'display', 'ছদ্মনাম'],
        'guardian_name' => ['guardian name', 'guardian', "father's name", 'father name', 'fathers name', 'spouse name', 'husband name', 'father / spouse name', 'father or spouse name', 'পিতার নাম', 'বাবার নাম', 'স্বামীর নাম', 'পিতা / স্বামীর নাম', 'অভিভাবকের নাম'],
        'date_of_birth' => ['date of birth', 'dob', 'birth date', 'birthday', 'জন্ম তারিখ', 'জন্মতারিখ'],
        'phone' => ['phone', 'mobile', 'mobile no', 'mobile number', 'phone no', 'phone number', 'contact', 'contact no', 'contact number', 'cell', 'মোবাইল', 'মোবাইল নম্বর', 'মোবাইল নাম্বার', 'মোবাইল নং', 'ফোন', 'ফোন নম্বর', 'ফোন নাম্বার', 'ফোন নং'],
        'email' => ['email', 'e-mail', 'email address', 'ইমেইল', 'ই-মেইল'],
        'nid_number' => ['nid', 'nid no', 'nid number', 'nid / passport', 'nid or passport', 'national id', 'passport', 'passport no', 'এনআইডি', 'এনআইডি নম্বর', 'এনআইডি / পাসপোর্ট', 'জাতীয় পরিচয়পত্র', 'জাতীয় পরিচয়পত্র নম্বর', 'পাসপোর্ট'],
        'address' => ['address', 'present address', 'permanent address', 'ঠিকানা', 'বর্তমান ঠিকানা', 'স্থায়ী ঠিকানা'],
        'stamp_number' => ['stamp', 'stamp no', 'stamp number', 'স্ট্যাম্প', 'স্ট্যাম্প নম্বর', 'স্ট্যাম্প নং', 'স্ট্যাম্প নাম্বার'],
        'cheque_number' => ['cheque', 'cheque no', 'cheque number', 'check no', 'check number', 'চেক', 'চেক নম্বর', 'চেক নং', 'চেক নাম্বার'],
        'is_channel_partner' => ['is channel partner', 'is partner', 'also channel partner', 'চ্যানেল পার্টনার কি না'],
        'channel_partner' => ['channel partner', 'channel partner phone', 'channel partner name', 'referred by', 'reference', 'referrer', 'চ্যানেল পার্টনার', 'রেফারেন্স', 'রেফার করেছেন'],
        'nominee_name' => ['nominee', 'nominee name', 'নমিনি', 'নমিনির নাম'],
        'nominee_nid_or_passport' => ['nominee nid', 'nominee nid / passport', 'nominee nid or passport', 'nominee passport', 'নমিনি nid', 'নমিনি nid / passport', 'নমিনির nid', 'নমিনির nid / passport', 'নমিনির এনআইডি', 'নমিনি এনআইডি', 'নমিনির জাতীয় পরিচয়পত্র'],
        'nominee_phone' => ['nominee phone', 'nominee mobile', 'nominee phone number', 'নমিনির মোবাইল', 'নমিনির ফোন', 'নমিনির ফোন নম্বর', 'নমিনির ফোন নাম্বার', 'নমিনির মোবাইল নম্বর', 'নমিনির মোবাইল নাম্বার'],
        'nominee_relation' => ['nominee relation', 'relation', 'relationship', 'relation to investor', 'সম্পর্ক', 'নমিনির সম্পর্ক'],
        'nominee_address' => ['nominee address', 'নমিনির ঠিকানা'],
        // Not an investor field: the amount is shown to the reviewer as a
        // note so the investment can be entered after confirming.
        'investment_amount' => ['investment', 'investment amount', 'amount', 'বিনিয়োগ', 'বিনিয়োগের পরিমাণ', 'ইনভেস্টমেন্ট', 'ইনভেস্টমেন্ট এমাউন্ট'],
    ],

    // Known columns that are not imported and not reported as unknown.
    'skip' => ['sl', 'sl no', 'serial', 'serial no', 'no', '#', 'ক্রমিক', 'ক্রমিক নং', 'ক্রম', 'ক্র নং'],

    // Values read as "yes" for the "is channel partner" column.
    'yes_values' => ['yes', 'y', 'true', '1', 'partner', 'হ্যাঁ', 'হা'],

    // The "Sample CSV" download: the owner's own investor sheet layout
    // (INV sheets). The title line above the header is optional.
    'sample' => [
        'title' => 'প্রজেক্টের নাম (শেয়ারিং মেশিন বিনিয়োগকারীর বিবরণ), ঠিকানা (INV 1 - 10/04/2026)',
        'headings' => ['SL', 'ইনভেস্টরদের নাম', 'পিতার নাম', 'ঠিকানা', 'ফোন নাম্বার', 'NID/Passport', 'নমিনীর নাম', 'নমিনী NID/Passport', 'নমিনীর ফোন নাম্বার', 'বিনিয়োগ (৳)', 'স্টাম্প নং.', 'চেক নং.'],
        'rows' => [
            ['১', 'ইনভেস্টরের নাম', 'পিতার নাম', 'বাসা, রোড, এলাকা, জেলা', '০১৭১২৩৪৫৬৭৮', '১৯৯০১২৩৪৫৬৭৮৯', 'নমিনীর নাম', '১২৩৪৫৬৭৮৯০', '০১৮১২৩৪৫৬৭৮', '200,000', '১২৩৪৫৬৭, ১২৩৪৫৬৮', 'ব্যাংক/চেক নম্বর'],
        ],
    ],

    // "Upload documents by NID": a word in the file name picks the kind of
    // copy (checked in this order). No match = the investor's own NID copy.
    'document_keywords' => [
        'nominee_nid' => ['nominee', 'নমিনি', 'নমিনী'],
        'signed_contract' => ['contract', 'agreement', 'deed', 'চুক্তি'],
        'cheque_copy' => ['cheque', 'check', 'চেক'],
        'stamp_copy' => ['stamp', 'স্ট্যাম্প', 'স্টাম্প'],
    ],

    // One import may hold at most this many investors.
    'max_rows' => 500,
];
