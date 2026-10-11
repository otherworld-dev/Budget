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
