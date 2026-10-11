/**
 * Spreadsheet statements (.xls, .xlsx, .ods) for the importer (#424).
 *
 * Some banks only export a spreadsheet, so these files are turned into a CSV
 * in the browser and uploaded in its place. The server only ever sees a CSV:
 * column mapping, presets, rules, duplicate detection and the import id all
 * work exactly as they do for a CSV the user saved from the spreadsheet by
 * hand, and nothing new reaches the server's parsers. The reader (SheetJS) is
 * loaded only when a spreadsheet is actually picked.
 *
 * Cells are written as their values, not as the spreadsheet displays them.
 * A displayed amount depends on the cell's number format (it may round, or
 * group thousands), and a displayed date on the locale Excel was set to, so
 * neither could be read back reliably.
 */
import { translate as t } from '@nextcloud/l10n';

export const SPREADSHEET_EXTENSIONS = ['xls', 'xlsx', 'ods'];

/** A spreadsheet that cannot be turned into rows, with a message to show */
export class SpreadsheetError extends Error {}

export function isSpreadsheet(fileName) {
    const match = /\.([^.]+)$/.exec(String(fileName));
    return match !== null && SPREADSHEET_EXTENSIONS.includes(match[1].toLowerCase());
}

const pad = value => String(value).padStart(2, '0');

/**
 * A number cell as text.
 *
 * Rounded to 15 significant digits, the most Excel shows, so arithmetic noise
 * in a stored value (0.30000000000000004) is written the way the bank's own
 * spreadsheet displays it.
 *
 * The importer reads a lone separator followed by exactly three digits, with
 * one to three digits before it, as grouping thousands ("1.234" is 1234; see
 * TransactionNormalizer::groupsThousands). A value written by this function
 * never groups thousands, so a trailing zero keeps 1.234 from being read as
 * 1234.
 */
export function numberText(value) {
    if (!Number.isFinite(value)) {
        return '';
    }
    const text = String(Number(value.toPrecision(15)));
    return /^-?[1-9]\d{0,2}\.\d{3}$/.test(text) ? text + '0' : text;
}

/**
 * A date cell as text. Spreadsheets store a date as a day count, shown
 * through a format, and Excel's default date format follows the locale of
 * whoever opens the file, so the same cell reads 10/11 in one country and
 * 11/10 in another. The day count is written as an unambiguous Y-m-d.
 *
 * Only the date is kept, since a transaction has no time. A time on its own
 * (a day count below one) is written as a time.
 *
 * @param {number} serial The day count
 * @param {object} ssf SheetJS's number-format helpers (XLSX.SSF)
 * @param {boolean} date1904 Whether the workbook counts days from 1904
 */
export function serialDateText(serial, ssf, date1904) {
    const parts = ssf.parse_date_code(serial, { date1904 });
    if (!parts) {
        return numberText(serial);
    }
    if (Math.floor(serial) === 0 && !date1904) {
        return `${pad(parts.H)}:${pad(parts.M)}:${pad(parts.S)}`;
    }
    return `${parts.y}-${pad(parts.m)}-${pad(parts.d)}`;
}

/**
 * One cell as text.
 *
 * @param {object|undefined} cell A SheetJS cell object
 * @param {object} ssf SheetJS's number-format helpers (XLSX.SSF)
 * @param {boolean} date1904 Whether the workbook counts days from 1904
 */
export function cellText(cell, ssf, date1904) {
    if (!cell || cell.v === undefined || cell.v === null) {
        return '';
    }
    switch (cell.t) {
    case 'n':
        return cell.z && ssf.is_date(cell.z)
            ? serialDateText(cell.v, ssf, date1904)
            : numberText(cell.v);
    case 'b':
        return cell.v ? 'TRUE' : 'FALSE';
    case 's':
        return String(cell.v).trim();
    default:
        // Error cells (#N/A) and anything else: what the spreadsheet shows
        return String(cell.w ?? '').trim();
    }
}

/**
 * Every row of a sheet as text, each as wide as the sheet's used range.
 *
 * @param {object} XLSX The SheetJS module
 * @param {object} sheet A SheetJS worksheet
 * @param {boolean} date1904 Whether the workbook counts days from 1904
 * @return {string[][]}
 */
export function sheetRows(XLSX, sheet, date1904) {
    if (!sheet || !sheet['!ref']) {
        return [];
    }
    const range = XLSX.utils.decode_range(sheet['!ref']);
    const rows = [];
    for (let r = range.s.r; r <= range.e.r; r++) {
        const row = [];
        for (let c = range.s.c; c <= range.e.c; c++) {
            row.push(cellText(sheet[XLSX.utils.encode_cell({ r, c })], XLSX.SSF, date1904));
        }
        rows.push(row);
    }
    return rows;
}

/**
 * The transaction table in a sheet's rows: the header row and everything
 * after it, each padded to one width. Rows above the header are dropped.
 *
 * A spreadsheet has no notion of a row's width the way a CSV line does, and
 * the CSV parser leans on width: it takes the widest width that repeats as
 * the table and skips lines of any other width as the bank's preamble
 * (account holder, period, balance). Written as they are, a data row with an
 * empty last cell would come out narrower than the table and be dropped. So
 * the width is decided here: the table is as wide as the widest row, and it
 * starts at the first row nearly that wide with at least two cells filled,
 * which allows for a header whose last column has no title.
 *
 * The preamble is dropped rather than written narrow because the server picks
 * the delimiter from the first line, and a preamble cell holding a semicolon
 * would make it split the whole file on semicolons.
 *
 * @param {string[][]} rows
 * @return {string[][]} The table, or an empty array if there is none
 */
export function tableRows(rows) {
    const widths = rows.map(row => {
        let width = row.length;
        while (width > 0 && row[width - 1] === '') {
            width--;
        }
        return width;
    });
    const tableWidth = widths.reduce((max, width) => Math.max(max, width), 0);
    if (tableWidth < 2) {
        return [];
    }
    const filled = row => row.reduce((count, cell) => count + (cell === '' ? 0 : 1), 0);
    const header = rows.findIndex((row, i) => widths[i] >= tableWidth - 1 && filled(row) >= 2);
    if (header === -1) {
        return [];
    }
    const table = [];
    for (let i = header; i < rows.length; i++) {
        if (widths[i] === 0) {
            continue;
        }
        const row = rows[i].slice(0, tableWidth);
        while (row.length < tableWidth) {
            row.push('');
        }
        table.push(row);
    }
    return table;
}

/**
 * Rows as comma-separated text. A line break inside a cell (a wrapped
 * description) becomes a space, so every row is one line.
 *
 * @param {string[][]} rows
 */
export function toCsv(rows) {
    return rows.map(row => row.map(cell => {
        const text = cell.replace(/\r\n|\r|\n/g, ' ');
        return /[",]/.test(text) ? `"${text.replace(/"/g, '""')}"` : text;
    }).join(',')).join('\n') + '\n';
}

const loadSheetJs = () => import(/* webpackChunkName: "xlsx" */ 'xlsx');

/**
 * A spreadsheet file as a CSV file to upload in its place: the first sheet
 * holding a table (a header and at least one row).
 *
 * @param {File} file
 * @param {Function} loadXlsx Loads the SheetJS module (replaced in tests)
 * @return {Promise<File>}
 * @throws {SpreadsheetError} If the file cannot be read or holds no table
 */
export async function spreadsheetToCsvFile(file, loadXlsx = loadSheetJs) {
    const XLSX = await loadXlsx();
    let workbook;
    try {
        workbook = XLSX.read(await file.arrayBuffer(), {
            type: 'array',
            // Keep each cell's number format, which is how a date cell is told apart
            cellNF: true,
            // A bank "XLS" that is really an HTML or text table: keep its cells as
            // written, rather than guessing which are numbers and dates
            raw: true,
        });
    } catch (error) {
        console.error('Failed to read spreadsheet:', error);
        throw new SpreadsheetError(t('budget', 'This spreadsheet could not be read. If it is password-protected, remove the password, or save it as CSV and import that.'));
    }

    const date1904 = Boolean(workbook.Workbook?.WBProps?.date1904);
    for (const name of workbook.SheetNames) {
        const rows = tableRows(sheetRows(XLSX, workbook.Sheets[name], date1904));
        if (rows.length >= 2) {
            const csvName = file.name.replace(/\.[^.]+$/, '') + '.csv';
            return new File([toCsv(rows)], csvName, { type: 'text/csv' });
        }
    }
    throw new SpreadsheetError(t('budget', 'This spreadsheet has no table of transactions to import.'));
}
