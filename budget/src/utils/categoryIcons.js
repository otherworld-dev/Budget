/**
 * Category icons (#440).
 *
 * A category stores its icon as a short key ('icon-home'). The keys predate
 * this module: the default categories were seeded with them in the hope that
 * Nextcloud's own icon classes would draw them, but most of those classes
 * don't exist, so the circles rendered empty. The icons are now inline SVGs
 * from Material Design Icons, keyed by the same strings so every stored value
 * keeps working. An unknown key (old data, a hand-edited import file) falls
 * back to the tag icon rather than rendering nothing.
 */
import { translate as t } from '@nextcloud/l10n';
import { onColour } from './amountColours.js';
import {
    mdiAccount, mdiAirplane, mdiBabyCarriage, mdiBank, mdiBed, mdiBike,
    mdiBookOpenVariant, mdiBriefcase, mdiBus, mdiCar, mdiCart, mdiCash,
    mdiChartLine, mdiClockOutline, mdiCodeTags, mdiCoffee, mdiCog,
    mdiContentCut, mdiCreditCard, mdiDumbbell, mdiFire, mdiFlash, mdiFlower,
    mdiFood, mdiGamepadVariant, mdiGasStation, mdiGift, mdiGlassMugVariant,
    mdiHandHeart, mdiHeart, mdiHome, mdiLaptop, mdiMedicalBag, mdiMusic,
    mdiPaw, mdiPercent, mdiPhone, mdiPiggyBank, mdiPill, mdiPlayCircle,
    mdiPlusCircle, mdiReceiptText, mdiRefresh, mdiSchool, mdiShieldCheck,
    mdiShopping, mdiSilverwareForkKnife, mdiSprout, mdiTagOutline, mdiTarget,
    mdiTelevision, mdiTicket, mdiTools, mdiTrain, mdiTshirtCrew, mdiUmbrella,
    mdiVideo, mdiWater, mdiWifi,
} from '@mdi/js';

export const DEFAULT_CATEGORY_ICON = 'icon-tag';

/** Picker order: key, SVG path, and a label for screen readers and tooltips. */
const ICONS = [
    ['icon-tag', mdiTagOutline, () => t('budget', 'Tag')],
    ['icon-home', mdiHome, () => t('budget', 'Home')],
    ['icon-flash', mdiFlash, () => t('budget', 'Electricity')],
    ['icon-water', mdiWater, () => t('budget', 'Water')],
    ['icon-fire', mdiFire, () => t('budget', 'Heating')],
    ['icon-wifi', mdiWifi, () => t('budget', 'Internet')],
    ['icon-phone', mdiPhone, () => t('budget', 'Phone')],
    ['icon-television', mdiTelevision, () => t('budget', 'TV')],
    ['icon-shopping-cart', mdiCart, () => t('budget', 'Groceries')],
    ['icon-food', mdiFood, () => t('budget', 'Food')],
    ['icon-restaurant', mdiSilverwareForkKnife, () => t('budget', 'Restaurant')],
    ['icon-coffee', mdiCoffee, () => t('budget', 'Coffee')],
    ['icon-beer', mdiGlassMugVariant, () => t('budget', 'Drinks')],
    ['icon-shopping-bag', mdiShopping, () => t('budget', 'Shopping')],
    ['icon-shirt', mdiTshirtCrew, () => t('budget', 'Clothing')],
    ['icon-scissors', mdiContentCut, () => t('budget', 'Haircut')],
    ['icon-car', mdiCar, () => t('budget', 'Car')],
    ['icon-gas-station', mdiGasStation, () => t('budget', 'Fuel')],
    ['icon-bus', mdiBus, () => t('budget', 'Bus')],
    ['icon-train', mdiTrain, () => t('budget', 'Train')],
    ['icon-bike', mdiBike, () => t('budget', 'Bike')],
    ['icon-airplane', mdiAirplane, () => t('budget', 'Travel')],
    ['icon-bed', mdiBed, () => t('budget', 'Hotel')],
    ['icon-medical', mdiMedicalBag, () => t('budget', 'Medical')],
    ['icon-pill', mdiPill, () => t('budget', 'Pharmacy')],
    ['icon-dumbbell', mdiDumbbell, () => t('budget', 'Fitness')],
    ['icon-heart', mdiHeart, () => t('budget', 'Heart')],
    ['icon-baby', mdiBabyCarriage, () => t('budget', 'Children')],
    ['icon-paw', mdiPaw, () => t('budget', 'Pets')],
    ['icon-school', mdiSchool, () => t('budget', 'Education')],
    ['icon-book', mdiBookOpenVariant, () => t('budget', 'Books')],
    ['icon-music', mdiMusic, () => t('budget', 'Music')],
    ['icon-video', mdiVideo, () => t('budget', 'Video')],
    ['icon-play', mdiPlayCircle, () => t('budget', 'Streaming')],
    ['icon-game', mdiGamepadVariant, () => t('budget', 'Games')],
    ['icon-ticket', mdiTicket, () => t('budget', 'Events')],
    ['icon-gift', mdiGift, () => t('budget', 'Gifts')],
    ['icon-hand-heart', mdiHandHeart, () => t('budget', 'Charity')],
    ['icon-flower', mdiFlower, () => t('budget', 'Garden')],
    ['icon-sprout', mdiSprout, () => t('budget', 'Plants')],
    ['icon-tools', mdiTools, () => t('budget', 'Repairs')],
    ['icon-laptop', mdiLaptop, () => t('budget', 'Computer')],
    ['icon-code', mdiCodeTags, () => t('budget', 'Software')],
    ['icon-settings', mdiCog, () => t('budget', 'Services')],
    ['icon-refresh', mdiRefresh, () => t('budget', 'Subscriptions')],
    ['icon-shield', mdiShieldCheck, () => t('budget', 'Insurance')],
    ['icon-umbrella', mdiUmbrella, () => t('budget', 'Protection')],
    ['icon-receipt', mdiReceiptText, () => t('budget', 'Bills')],
    ['icon-percent', mdiPercent, () => t('budget', 'Interest')],
    ['icon-card', mdiCreditCard, () => t('budget', 'Card')],
    ['icon-cash', mdiCash, () => t('budget', 'Cash')],
    ['icon-bank', mdiBank, () => t('budget', 'Bank')],
    ['icon-piggy-bank', mdiPiggyBank, () => t('budget', 'Savings')],
    ['icon-chart-line', mdiChartLine, () => t('budget', 'Investments')],
    ['icon-briefcase', mdiBriefcase, () => t('budget', 'Work')],
    ['icon-user', mdiAccount, () => t('budget', 'Person')],
    ['icon-clock', mdiClockOutline, () => t('budget', 'Time')],
    ['icon-target', mdiTarget, () => t('budget', 'Goal')],
    ['icon-plus', mdiPlusCircle, () => t('budget', 'Other')],
];

const PATHS = new Map(ICONS.map(([key, path]) => [key, path]));

/** Every icon the picker offers, in display order. */
export function categoryIconChoices() {
    return ICONS.map(([key, path, label]) => ({ key, path, label: label() }));
}

/** The stored key if it names a known icon, otherwise the default. */
export function resolveCategoryIcon(key) {
    return PATHS.has(key) ? key : DEFAULT_CATEGORY_ICON;
}

/**
 * The icon colour that reads on a category's circle. Colours are #rgb or
 * #rrggbb (ValidationService::validateColor); anything else gets white, which
 * suits the grey fallback circle.
 */
export function categoryIconColour(fill) {
    let hex = typeof fill === 'string' ? fill.trim() : '';
    if (/^#[0-9a-f]{3}$/i.test(hex)) {
        hex = '#' + [...hex.slice(1)].map(c => c + c).join('');
    }
    return /^#[0-9a-f]{6}$/i.test(hex) ? onColour(hex) : '#ffffff';
}

/** Inline SVG markup for a category icon; safe to interpolate (no user text). */
export function categoryIconSvg(key, size = 14) {
    const path = PATHS.get(resolveCategoryIcon(key));
    return `<svg width="${size}" height="${size}" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true" focusable="false"><path d="${path}"/></svg>`;
}
