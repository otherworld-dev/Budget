# Spreadsheet fixtures

Bank-statement spreadsheets for the server-side spreadsheet reader (#424).
Written once with SheetJS 0.20.3 (and `xlwt.xls` with Python xlwt, a second,
independent BIFF writer); the generator scripts are not kept, the files are.

- `statement.xlsx`, `statement.xls`, `statement.ods`: the same statement, a
  preamble line with a semicolon, a blank row, a header, three rows with
  dd/mm/yyyy and Excel's built-in m/d/yy dates, a `#,##0.00` amount, an empty
  last cell, a comma, a quote and an accented character. `statement.xls` is
  small enough to sit in the OLE mini stream.
- `statement-1904.xlsx`: the same statement in the 1904 date system.
- `statement-large.xls`: 300 rows, in regular OLE sectors.
- `cover-first.xlsx`: a first sheet with no table, the statement second.
- `xlsx-named.xls`: an .xlsx file named .xls.
- `html-utf8.xls`, `html-cp1252.xls`, `html-nometa-cp1252.xls`: HTML tables
  saved as .xls, with `<th>` headings and a colspan totals row.
- `tab-separated.xls`: a tab-separated text file named .xls.
- `encrypted.xlsx`: an OLE container with EncryptionInfo (a password-protected xlsx).
- `filepass.xls`: a BIFF8 workbook with a FILEPASS record.
- `biff5.xls`: an Excel 95 workbook (a `Book` stream).
- `xlwt.xls`: 600 rows by xlwt, shared strings split across CONTINUE records.
- `cover-labels.ods`: a first sheet of two "label | value" rows, the
  statement second, with a line break, a tab and runs of spaces inside
  cells, a comment, a two-paragraph cell, an amount below 0.0001 and
  15- and 16-digit numbers. Written by hand (a minimal ODS, not by an
  office suite).
- `html-preamble-rowspan.xls`: "label | value" rows over a three-column
  HTML table, and a date cell spanning two rows. Written by hand.
- `accounts-per-sheet.ods`: one sheet per account, the same columns, the
  second account with more rows. The first sheet is the statement.
- `statement-then-summary.ods`: a short statement under an "Account |
  number" line, then a longer three-column summary sheet. The statement
  is imported, not the summary.
- `ods-spaces.ods`: under 800 bytes, one cell of 2,000 paragraphs each
  holding a run of 1,048,000 spaces (`<text:s text:c="1048000"/>`): each
  paragraph under the cell limit, the cell two gigabytes. It took the PHP
  process down on memory_limit; it must be refused, quickly.

`golden/<fixture>.csv` is the exact CSV each fixture converts to, checked by
`SpreadsheetFixturesTest`. Cell text is part of a row's import id when the
row has none of its own, so a golden file must never be regenerated to make
a change pass once spreadsheets have shipped: a changed output re-keys
every row of a statement imported before, and importing it again
duplicates it.
