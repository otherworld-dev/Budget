/**
 * Formatting utilities for currency, dates, and numbers
 * All functions are pure - they accept required data as parameters
 */

/**
 * Currency configuration with symbol and position metadata
 * Position: 'prefix' = symbol before amount (e.g., $500), 'suffix' = symbol after amount (e.g., 500 kr)
 * Prefix currencies have no space, suffix currencies have a space before the symbol
 */
const CURRENCY_CONFIG = {
    // Americas
    'USD': { symbol: '$', position: 'prefix' },
    'CAD': { symbol: 'C$', position: 'prefix' },
    'MXN': { symbol: 'MX$', position: 'prefix' },
    'BRL': { symbol: 'R$', position: 'prefix' },
    'ARS': { symbol: 'AR$', position: 'prefix' },
    'CLP': { symbol: 'CL$', position: 'prefix' },
    'COP': { symbol: 'CO$', position: 'prefix' },
    'PEN': { symbol: 'S/', position: 'prefix' },
    // Europe
    'EUR': { symbol: '€', position: 'prefix' },
    'GBP': { symbol: '£', position: 'prefix' },
    'CHF': { symbol: 'CHF', position: 'suffix' },
    'SEK': { symbol: 'kr', position: 'suffix' },
    'NOK': { symbol: 'kr', position: 'suffix' },
    'DKK': { symbol: 'kr', position: 'suffix' },
    'PLN': { symbol: 'zł', position: 'suffix' },
    'CZK': { symbol: 'Kč', position: 'suffix' },
    'HUF': { symbol: 'Ft', position: 'suffix' },
    'RON': { symbol: 'lei', position: 'suffix' },
    'UAH': { symbol: '₴', position: 'prefix' },
    'ISK': { symbol: 'kr', position: 'suffix' },
    'RUB': { symbol: '₽', position: 'prefix' },
    'TRY': { symbol: '₺', position: 'prefix' },
    // Asia-Pacific
    'JPY': { symbol: '¥', position: 'prefix' },
    'CNY': { symbol: '¥', position: 'prefix' },
    'KRW': { symbol: '₩', position: 'prefix' },
    'INR': { symbol: '₹', position: 'prefix' },
    'IDR': { symbol: 'Rp', position: 'prefix' },
    'THB': { symbol: '฿', position: 'prefix' },
    'PHP': { symbol: '₱', position: 'prefix' },
    'MYR': { symbol: 'RM', position: 'prefix' },
    'VND': { symbol: '₫', position: 'suffix' },
    'TWD': { symbol: 'NT$', position: 'prefix' },
    'SGD': { symbol: 'S$', position: 'prefix' },
    'HKD': { symbol: 'HK$', position: 'prefix' },
    'PKR': { symbol: 'Rs', position: 'prefix' },
    'BDT': { symbol: '৳', position: 'prefix' },
    'AUD': { symbol: 'A$', position: 'prefix' },
    'NZD': { symbol: 'NZ$', position: 'prefix' },
    // Middle East & Africa
    'AED': { symbol: 'AED', position: 'prefix' },
    'SAR': { symbol: 'SAR', position: 'prefix' },
    'ILS': { symbol: '₪', position: 'prefix' },
    'EGP': { symbol: 'E£', position: 'prefix' },
    'NGN': { symbol: '₦', position: 'prefix' },
    'KES': { symbol: 'KSh', position: 'prefix' },
    'ZAR': { symbol: 'R', position: 'prefix' },
};

/**
 * Format currency amount according to user settings
 * @param {number} amount - Amount to format
 * @param {string|null} currency - Currency code (e.g., 'USD', 'EUR')
 * @param {object} settings - User settings object
 * @returns {string} Formatted currency string
 */
export function formatCurrency(amount, currency, settings) {
    const currencyCode = currency || getPrimaryCurrency([], settings);
    const decimals = parseInt(settings.number_format_decimals) || 2;
    const decimalSep = settings.number_format_decimal_sep || '.';
    const thousandsSep = settings.number_format_thousands_sep ?? ',';

    // Format the number manually using user settings
    const absAmount = Math.abs(amount);
    const parts = absAmount.toFixed(decimals).split('.');
    const intPart = parts[0].replace(/\B(?=(\d{3})+(?!\d))/g, thousandsSep);
    const decPart = parts[1] || '';

    // Get currency configuration (symbol + position)
    const config = CURRENCY_CONFIG[currencyCode] || { symbol: currencyCode, position: 'prefix' };
    const { symbol, position } = config;

    const formattedNumber = decimals > 0 ? `${intPart}${decimalSep}${decPart}` : intPart;
    const sign = amount < 0 ? '-' : '';

    // Apply symbol based on position (suffix currencies get a space before the symbol)
    if (position === 'suffix') {
        return `${sign}${formattedNumber} ${symbol}`;
    } else {
        return `${sign}${symbol}${formattedNumber}`;
    }
}

/**
 * Get primary currency based on account balances
 * @param {array} accounts - Array of account objects
 * @param {object} settings - User settings object
 * @returns {string} Primary currency code
 */
export function getPrimaryCurrency(accounts, settings) {
    // Get default currency from settings (matches backend SettingController default of 'GBP')
    const defaultCurrency = settings?.default_currency || 'GBP';

    // Default fallback to user's setting
    if (!Array.isArray(accounts) || accounts.length === 0) {
        return defaultCurrency;
    }

    // Weight currencies by absolute balance (same logic as backend ForecastService)
    const currencyWeights = {};
    accounts.forEach(account => {
        const currency = account.currency || defaultCurrency;
        const balance = Math.abs(parseFloat(account.balance) || 0);
        currencyWeights[currency] = (currencyWeights[currency] || 0) + balance;
    });

    // Find currency with highest weight
    let primaryCurrency = defaultCurrency;
    let maxWeight = 0;
    for (const [currency, weight] of Object.entries(currencyWeights)) {
        if (weight > maxWeight) {
            maxWeight = weight;
            primaryCurrency = currency;
        }
    }

    return primaryCurrency;
}

/**
 * Format date string according to user settings
 * @param {string} dateStr - Date string in YYYY-MM-DD format
 * @param {object} settings - User settings object
 * @returns {string} Formatted date string
 */
export function formatDate(dateStr, settings) {
    if (!dateStr) return '';

    // Parse date string directly to avoid timezone conversion issues
    // Assumes dateStr is in YYYY-MM-DD format from backend
    const parts = dateStr.split(/[-/]/);
    if (parts.length !== 3) {
        // Fallback for unexpected format
        return dateStr;
    }

    const year = parseInt(parts[0], 10);
    const month = parseInt(parts[1], 10);
    const day = parseInt(parts[2], 10);

    // Use user's date format preference from settings
    const format = settings?.date_format || 'Y-m-d';

    // Format the date according to PHP date format codes
    const pad = (num) => String(num).padStart(2, '0');
    const monthNames = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
    const monthName = monthNames[month - 1];

    // Convert PHP date format to actual date string
    return format
        .replace('Y', year)
        .replace('m', pad(month))
        .replace('d', pad(day))
        .replace('M', monthName)
        .replace('j', day);
}

/**
 * Format account type for display
 * @param {string} type - Account type code
 * @returns {string} Formatted account type name
 */
export function formatAccountType(type) {
    if (!type) return '';
    const typeNames = {
        checking: 'Checking',
        savings: 'Savings',
        credit_card: 'Credit Card',
        investment: 'Investment',
        cash: 'Cash',
        loan: 'Loan',
        mortgage: 'Mortgage',
        pension: 'Pension'
    };
    return typeNames[type] || type.replace(/_/g, ' ').replace(/\b\w/g, c => c.toUpperCase());
}

/**
 * Format currency in compact form (with K/M suffix)
 * @param {number} value - Amount to format
 * @param {string|null} currency - Currency code
 * @param {object} settings - User settings object
 * @returns {string} Compact formatted currency string
 */
export function formatCurrencyCompact(value, currency, settings) {
    const currencyCode = currency || getPrimaryCurrency([], settings);
    const config = CURRENCY_CONFIG[currencyCode] || { symbol: currencyCode, position: 'prefix' };
    const { symbol, position } = config;

    const decimals = parseInt(settings.number_format_decimals) || 2;
    const decimalSep = settings.number_format_decimal_sep || '.';
    const thousandsSep = settings.number_format_thousands_sep ?? ',';

    let scaledValue, suffix;

    if (Math.abs(value) >= 1000000) {
        scaledValue = value / 1000000;
        suffix = 'M';
    } else if (Math.abs(value) >= 1000) {
        scaledValue = value / 1000;
        suffix = 'K';
    } else {
        // No scaling needed, use regular formatting
        return formatCurrency(value, currency, settings);
    }

    // Format the scaled number
    const absScaled = Math.abs(scaledValue);
    const formatted = absScaled.toFixed(1);
    const parts = formatted.split('.');
    const intPart = parts[0].replace(/\B(?=(\d{3})+(?!\d))/g, thousandsSep);
    const decPart = parts[1] || '';
    const formattedNumber = decPart ? `${intPart}${decimalSep}${decPart}` : intPart;
    const sign = scaledValue < 0 ? '-' : '';

    // Apply currency symbol and K/M suffix based on position
    if (position === 'suffix') {
        return `${sign}${formattedNumber}${suffix} ${symbol}`;
    } else {
        return `${sign}${symbol}${formattedNumber}${suffix}`;
    }
}

/**
 * Generate hash of accounts for caching purposes
 * @param {array} accounts - Array of account objects
 * @returns {string} Hash string
 */
export function getAccountsHash(accounts) {
    if (!Array.isArray(accounts)) return '';
    return accounts.map(a => `${a.id}:${a.currency}:${a.balance}`).join('|');
}

/**
 * Format a Date object as YYYY-MM-DD without timezone conversion.
 * This prevents off-by-one day errors when working with local dates.
 *
 * @param {Date} date - Date object to format
 * @returns {string} Date string in YYYY-MM-DD format
 */
export function formatDateForAPI(date) {
    const year = date.getFullYear();
    const month = String(date.getMonth() + 1).padStart(2, '0');
    const day = String(date.getDate()).padStart(2, '0');
    return `${year}-${month}-${day}`;
}

/**
 * Get today's date as YYYY-MM-DD string without timezone conversion.
 *
 * @returns {string} Today's date in YYYY-MM-DD format
 */
export function getTodayDateString() {
    return formatDateForAPI(new Date());
}

/**
 * Get the first day of a month as YYYY-MM-DD string.
 *
 * @param {number} year - Full year (e.g., 2026)
 * @param {number} month - Month number (1-12, NOT 0-11)
 * @returns {string} First day of month in YYYY-MM-DD format
 */
export function getMonthStart(year, month) {
    return `${year}-${String(month).padStart(2, '0')}-01`;
}

/**
 * Get the last day of a month as YYYY-MM-DD string.
 *
 * @param {number} year - Full year (e.g., 2026)
 * @param {number} month - Month number (1-12, NOT 0-11)
 * @returns {string} Last day of month in YYYY-MM-DD format
 */
export function getMonthEnd(year, month) {
    const lastDay = new Date(year, month, 0).getDate();
    return `${year}-${String(month).padStart(2, '0')}-${String(lastDay).padStart(2, '0')}`;
}

/**
 * Get date range for a budget period.
 *
 * @param {string} period - Period type: 'weekly', 'monthly', 'quarterly', 'yearly'
 * @returns {object} Object with {start, end, label} date strings
 */
export function getPeriodDateRange(period) {
    const now = new Date();

    switch (period) {
        case 'weekly': {
            // Monday to Sunday of current week
            const weekStart = new Date(now);
            const dayOfWeek = weekStart.getDay();
            const daysToMonday = dayOfWeek === 0 ? -6 : 1 - dayOfWeek;
            weekStart.setDate(weekStart.getDate() + daysToMonday);

            const weekEnd = new Date(weekStart);
            weekEnd.setDate(weekEnd.getDate() + 6);

            return {
                start: formatDateForAPI(weekStart),
                end: formatDateForAPI(weekEnd),
                label: `Week of ${weekStart.toLocaleDateString('en-US', { month: 'short', day: 'numeric' })}`
            };
        }

        case 'monthly': {
            // 1st to last day of current month
            const monthStart = new Date(now.getFullYear(), now.getMonth(), 1);
            const monthEnd = new Date(now.getFullYear(), now.getMonth() + 1, 0);

            return {
                start: formatDateForAPI(monthStart),
                end: formatDateForAPI(monthEnd),
                label: now.toLocaleDateString('en-US', { month: 'long', year: 'numeric' })
            };
        }

        case 'quarterly': {
            // First to last day of current quarter
            const quarter = Math.ceil((now.getMonth() + 1) / 3);
            const quarterStartMonth = (quarter - 1) * 3;
            const quarterStart = new Date(now.getFullYear(), quarterStartMonth, 1);
            const quarterEnd = new Date(now.getFullYear(), quarterStartMonth + 3, 0);

            return {
                start: formatDateForAPI(quarterStart),
                end: formatDateForAPI(quarterEnd),
                label: `Q${quarter} ${now.getFullYear()}`
            };
        }

        case 'yearly': {
            // Jan 1 to Dec 31 of current year
            const yearStart = new Date(now.getFullYear(), 0, 1);
            const yearEnd = new Date(now.getFullYear(), 11, 31);

            return {
                start: formatDateForAPI(yearStart),
                end: formatDateForAPI(yearEnd),
                label: `${now.getFullYear()}`
            };
        }

        default:
            // Default to monthly
            return getPeriodDateRange('monthly');
    }
}

/**
 * Pro-rate a budget amount from one period to another.
 *
 * @param {number} amount - Budget amount in the source period
 * @param {string} fromPeriod - Source period: 'weekly', 'monthly', 'quarterly', 'yearly'
 * @param {string} toPeriod - Target period: 'weekly', 'monthly', 'quarterly', 'yearly'
 * @returns {number} Pro-rated budget amount
 */
export function prorateBudget(amount, fromPeriod, toPeriod) {
    if (fromPeriod === toPeriod) {
        return amount;
    }

    // Conversion ratios (all relative to yearly)
    const yearlyMultipliers = {
        'weekly': 52,
        'monthly': 12,
        'quarterly': 4,
        'yearly': 1
    };

    // Convert to yearly amount first
    const yearlyAmount = amount * yearlyMultipliers[fromPeriod];

    // Convert from yearly to target period (full precision preserved for storage)
    return yearlyAmount / yearlyMultipliers[toPeriod];
}
