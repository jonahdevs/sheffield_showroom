/**
 * The countries a number can be given in, East Africa first and the rest
 * alphabetically — the order the storefront uses, because a reader here is
 * looking for the same handful at the top.
 *
 * The flag is not stored: it is fetched by ISO code, since the emoji a list
 * like this usually carries renders as two bare letters on Windows.
 */
export type DialCode = {
    iso: string;
    name: string;
    dial: string;
};

export const DIAL_CODES: DialCode[] = [
    { iso: 'KE', name: 'Kenya', dial: '+254' },
    { iso: 'UG', name: 'Uganda', dial: '+256' },
    { iso: 'TZ', name: 'Tanzania', dial: '+255' },
    { iso: 'RW', name: 'Rwanda', dial: '+250' },
    { iso: 'ET', name: 'Ethiopia', dial: '+251' },
    { iso: 'SS', name: 'South Sudan', dial: '+211' },
    { iso: 'BI', name: 'Burundi', dial: '+257' },
    { iso: 'DJ', name: 'Djibouti', dial: '+253' },
    { iso: 'ER', name: 'Eritrea', dial: '+291' },
    { iso: 'SO', name: 'Somalia', dial: '+252' },
    { iso: 'DZ', name: 'Algeria', dial: '+213' },
    { iso: 'AO', name: 'Angola', dial: '+244' },
    { iso: 'BJ', name: 'Benin', dial: '+229' },
    { iso: 'BW', name: 'Botswana', dial: '+267' },
    { iso: 'BF', name: 'Burkina Faso', dial: '+226' },
    { iso: 'CM', name: 'Cameroon', dial: '+237' },
    { iso: 'CV', name: 'Cape Verde', dial: '+238' },
    { iso: 'CF', name: 'Central African Rep.', dial: '+236' },
    { iso: 'TD', name: 'Chad', dial: '+235' },
    { iso: 'KM', name: 'Comoros', dial: '+269' },
    { iso: 'CG', name: 'Congo', dial: '+242' },
    { iso: 'CD', name: 'Congo (DRC)', dial: '+243' },
    { iso: 'CI', name: "Côte d'Ivoire", dial: '+225' },
    { iso: 'EG', name: 'Egypt', dial: '+20' },
    { iso: 'GQ', name: 'Equatorial Guinea', dial: '+240' },
    { iso: 'GA', name: 'Gabon', dial: '+241' },
    { iso: 'GM', name: 'Gambia', dial: '+220' },
    { iso: 'GH', name: 'Ghana', dial: '+233' },
    { iso: 'GN', name: 'Guinea', dial: '+224' },
    { iso: 'GW', name: 'Guinea-Bissau', dial: '+245' },
    { iso: 'LS', name: 'Lesotho', dial: '+266' },
    { iso: 'LR', name: 'Liberia', dial: '+231' },
    { iso: 'LY', name: 'Libya', dial: '+218' },
    { iso: 'MG', name: 'Madagascar', dial: '+261' },
    { iso: 'MW', name: 'Malawi', dial: '+265' },
    { iso: 'ML', name: 'Mali', dial: '+223' },
    { iso: 'MR', name: 'Mauritania', dial: '+222' },
    { iso: 'MU', name: 'Mauritius', dial: '+230' },
    { iso: 'MA', name: 'Morocco', dial: '+212' },
    { iso: 'MZ', name: 'Mozambique', dial: '+258' },
    { iso: 'NA', name: 'Namibia', dial: '+264' },
    { iso: 'NE', name: 'Niger', dial: '+227' },
    { iso: 'NG', name: 'Nigeria', dial: '+234' },
    { iso: 'RE', name: 'Réunion', dial: '+262' },
    { iso: 'SN', name: 'Senegal', dial: '+221' },
    { iso: 'SL', name: 'Sierra Leone', dial: '+232' },
    { iso: 'ZA', name: 'South Africa', dial: '+27' },
    { iso: 'SD', name: 'Sudan', dial: '+249' },
    { iso: 'SZ', name: 'Eswatini', dial: '+268' },
    { iso: 'TG', name: 'Togo', dial: '+228' },
    { iso: 'TN', name: 'Tunisia', dial: '+216' },
    { iso: 'ZM', name: 'Zambia', dial: '+260' },
    { iso: 'ZW', name: 'Zimbabwe', dial: '+263' },
    { iso: 'US', name: 'United States', dial: '+1' },
    { iso: 'CA', name: 'Canada', dial: '+1' },
    { iso: 'MX', name: 'Mexico', dial: '+52' },
    { iso: 'BR', name: 'Brazil', dial: '+55' },
    { iso: 'AR', name: 'Argentina', dial: '+54' },
    { iso: 'CO', name: 'Colombia', dial: '+57' },
    { iso: 'CL', name: 'Chile', dial: '+56' },
    { iso: 'PE', name: 'Peru', dial: '+51' },
    { iso: 'GB', name: 'United Kingdom', dial: '+44' },
    { iso: 'DE', name: 'Germany', dial: '+49' },
    { iso: 'FR', name: 'France', dial: '+33' },
    { iso: 'IT', name: 'Italy', dial: '+39' },
    { iso: 'ES', name: 'Spain', dial: '+34' },
    { iso: 'NL', name: 'Netherlands', dial: '+31' },
    { iso: 'PT', name: 'Portugal', dial: '+351' },
    { iso: 'SE', name: 'Sweden', dial: '+46' },
    { iso: 'NO', name: 'Norway', dial: '+47' },
    { iso: 'CH', name: 'Switzerland', dial: '+41' },
    { iso: 'AE', name: 'UAE', dial: '+971' },
    { iso: 'SA', name: 'Saudi Arabia', dial: '+966' },
    { iso: 'QA', name: 'Qatar', dial: '+974' },
    { iso: 'KW', name: 'Kuwait', dial: '+965' },
    { iso: 'TR', name: 'Turkey', dial: '+90' },
    { iso: 'IN', name: 'India', dial: '+91' },
    { iso: 'CN', name: 'China', dial: '+86' },
    { iso: 'JP', name: 'Japan', dial: '+81' },
    { iso: 'SG', name: 'Singapore', dial: '+65' },
    { iso: 'AU', name: 'Australia', dial: '+61' },
    { iso: 'PK', name: 'Pakistan', dial: '+92' },
    { iso: 'BD', name: 'Bangladesh', dial: '+880' },
    { iso: 'PH', name: 'Philippines', dial: '+63' },
    { iso: 'ID', name: 'Indonesia', dial: '+62' },
];

/** Where a country's flag is drawn from, keyed by its ISO code. */
export function flagUrl(iso: string): string {
    return `https://flagcdn.com/${iso.toLowerCase()}.svg`;
}

/**
 * Splits a stored number into the code it starts with and the rest. The longest
 * code wins, or `+1` would claim every `+1…` number before `+1` itself is
 * reached — and an unrecognised number keeps its digits rather than being
 * silently re-coded.
 */
export function splitDial(value: string): { dial: string; number: string } {
    const match = [...DIAL_CODES]
        .sort((a, b) => b.dial.length - a.dial.length)
        .find((code) => value.startsWith(code.dial));

    if (!match) {
        return { dial: DIAL_CODES[0].dial, number: value.trim() };
    }

    return { dial: match.dial, number: value.slice(match.dial.length).trim() };
}
