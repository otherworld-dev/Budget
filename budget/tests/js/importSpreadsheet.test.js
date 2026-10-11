// @vitest-environment node
/**
 * Spreadsheet statements (#424): an .xls/.xlsx/.ods file is converted to CSV
 * in the browser and uploaded in its place, so what matters is the CSV it
 * becomes, read the way the server's CSV parser reads it.
 *
 * Node rather than jsdom: the conversion reads the file with
 * File.arrayBuffer(), which jsdom's File does not have.
 */

import { describe, it, expect, vi } from 'vitest';
import * as XLSX from 'xlsx';

vi.mock('@nextcloud/l10n', () => ({
    translate: (_app, text) => text,
}));

import {
    isSpreadsheet,
    numberText,
    tableRows,
    toCsv,
    spreadsheetToCsvFile,
    SpreadsheetError,
} from '../../src/modules/import/spreadsheet.js';

const loadXlsx = () => Promise.resolve(XLSX);

/** Excel's day count for a date */
const serial = (y, m, d) => (Date.UTC(y, m - 1, d) - Date.UTC(1899, 11, 30)) / 86400000;

/** A workbook of one sheet built from rows, written in the given format */
function workbookFile(rows, bookType, name) {
    const sheet = XLSX.utils.aoa_to_sheet(rows);
    const book = XLSX.utils.book_new();
    XLSX.utils.book_append_sheet(book, sheet, 'Movimenti');
    const bytes = XLSX.write(book, { type: 'array', bookType });
    return new File([bytes], name);
}

const csvOf = async file => (await spreadsheetToCsvFile(file, loadXlsx)).text();

describe('isSpreadsheet', () => {
    it('matches spreadsheet extensions in any case', () => {
        expect(isSpreadsheet('estratto.xls')).toBe(true);
        expect(isSpreadsheet('Statement.XLSX')).toBe(true);
        expect(isSpreadsheet('export.ods')).toBe(true);
    });

    it('leaves every other file to the server', () => {
        expect(isSpreadsheet('statement.csv')).toBe(false);
        expect(isSpreadsheet('statement.ofx')).toBe(false);
        expect(isSpreadsheet('xls')).toBe(false);
        expect(isSpreadsheet('statement.xls.csv')).toBe(false);
    });
});

describe('numberText', () => {
    it('writes a value without arithmetic noise', () => {
        expect(numberText(0.1 + 0.2)).toBe('0.3');
        expect(numberText(-1234.5)).toBe('-1234.5');
        expect(numberText(-45)).toBe('-45');
    });

    it('never writes a value the importer would read as grouped thousands', () => {
        // "1.234" is read as 1234 by TransactionNormalizer::groupsThousands
        expect(numberText(1.234)).toBe('1.2340');
        expect(numberText(-12.345)).toBe('-12.3450');
        expect(numberText(1234.567)).toBe('1234.567');
    });
});

describe('tableRows', () => {
    it('drops the preamble and pads every row to the table width', () => {
        const rows = [
            ['Estratto conto', '', '', ''],
            ['Intestatario', 'Mario Rossi', '', ''],
            ['', '', '', ''],
            ['Data', 'Descrizione', 'Importo', 'Note'],
            ['2026-10-01', 'Caffè', '-1.5', ''],
            ['', '', '', ''],
            ['2026-10-02', 'Stipendio', '2000', 'Ottobre'],
        ];
        expect(tableRows(rows)).toEqual([
            ['Data', 'Descrizione', 'Importo', 'Note'],
            ['2026-10-01', 'Caffè', '-1.5', ''],
            ['2026-10-02', 'Stipendio', '2000', 'Ottobre'],
        ]);
    });

    it('takes a header whose last column has no title', () => {
        const rows = [
            ['Data', 'Descrizione', 'Importo', ''],
            ['2026-10-01', 'Caffè', '-1.5', 'x'],
        ];
        expect(tableRows(rows)[0]).toEqual(['Data', 'Descrizione', 'Importo', '']);
    });

    it('finds no table in a sheet of single cells', () => {
        expect(tableRows([['Nessun movimento'], ['']])).toEqual([]);
        expect(tableRows([])).toEqual([]);
    });
});

describe('toCsv', () => {
    it('quotes cells holding a comma or quote and keeps each row on one line', () => {
        expect(toCsv([['a', 'b, c', 'say "hi"', 'two\nlines']]))
            .toBe('a,"b, c","say ""hi""",two lines\n');
    });
});

describe('spreadsheetToCsvFile', () => {
    const statement = [
        // A semicolon in the preamble would make the server split on semicolons
        ['Conto; IT60X0542811101000000123456'],
        [],
        ['Data', 'Valuta', 'Descrizione', 'Importo', 'Note'],
        [
            { t: 'n', v: serial(2026, 10, 1), z: 'dd/mm/yyyy' },
            // Excel's built-in date format, which displays per the viewer's locale
            { t: 'n', v: serial(2026, 10, 3), z: 'm/d/yy' },
            'Pagamento POS, Bar Centrale',
            { t: 'n', v: -1234.5, z: '#,##0.00' },
            '',
        ],
        [
            { t: 'n', v: serial(2026, 10, 10), z: 'dd/mm/yyyy' },
            { t: 'n', v: serial(2026, 10, 10), z: 'm/d/yy' },
            'Bonifico stipendio',
            2000,
            'Ottobre',
        ],
    ];
    const expected = [
        'Data,Valuta,Descrizione,Importo,Note',
        '2026-10-01,2026-10-03,"Pagamento POS, Bar Centrale",-1234.5,',
        '2026-10-10,2026-10-10,Bonifico stipendio,2000,Ottobre',
        '',
    ].join('\n');

    it('converts an .xlsx statement', async () => {
        expect(await csvOf(workbookFile(statement, 'xlsx', 'estratto.xlsx'))).toBe(expected);
    });

    it('converts an old binary .xls statement', async () => {
        expect(await csvOf(workbookFile(statement, 'biff8', 'estratto.xls'))).toBe(expected);
    });

    it('converts an .ods statement', async () => {
        expect(await csvOf(workbookFile(statement, 'ods', 'estratto.ods'))).toBe(expected);
    });

    it('names the upload after the statement', async () => {
        const file = await spreadsheetToCsvFile(workbookFile(statement, 'xlsx', 'estratto.conto.xlsx'), loadXlsx);
        expect(file.name).toBe('estratto.conto.csv');
        expect(file.type).toBe('text/csv');
    });

    it('keeps the cells of an "XLS" that is really an HTML table as written', async () => {
        const html = '<html><head><meta charset="utf-8"></head><body><table>'
            + '<tr><td>Data</td><td>Descrizione</td><td>Importo</td></tr>'
            + '<tr><td>03/10/2026</td><td>Caffè</td><td>-1.234,56</td></tr>'
            + '</table></body></html>';
        const file = new File([new TextEncoder().encode(html)], 'movimenti.xls');
        expect(await csvOf(file)).toBe('Data,Descrizione,Importo\n03/10/2026,Caffè,"-1.234,56"\n');
    });

    it('skips a sheet with no table for the first one that has one', async () => {
        const book = XLSX.utils.book_new();
        XLSX.utils.book_append_sheet(book, XLSX.utils.aoa_to_sheet([['Riepilogo']]), 'Cover');
        XLSX.utils.book_append_sheet(book, XLSX.utils.aoa_to_sheet(statement), 'Movimenti');
        const file = new File([XLSX.write(book, { type: 'array', bookType: 'xlsx' })], 'estratto.xlsx');
        expect(await csvOf(file)).toBe(expected);
    });

    it('refuses a spreadsheet with no table', async () => {
        const file = workbookFile([['Nessun movimento nel periodo']], 'xlsx', 'vuoto.xlsx');
        await expect(spreadsheetToCsvFile(file, loadXlsx)).rejects.toBeInstanceOf(SpreadsheetError);
    });

    it('refuses a file it cannot read', async () => {
        const broken = new Uint8Array([0x50, 0x4B, 0x03, 0x04, 0x14, 0x00, 0x00, 0x00, 0xFF, 0xFF]);
        const spy = vi.spyOn(console, 'error').mockImplementation(() => {});
        await expect(spreadsheetToCsvFile(new File([broken], 'rotto.xlsx'), loadXlsx))
            .rejects.toBeInstanceOf(SpreadsheetError);
        spy.mockRestore();
    });
});
