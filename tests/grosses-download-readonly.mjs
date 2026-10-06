// Read-only verification of actual browser downloads; no workbook edits/exports.
import fs from 'node:fs/promises';
import path from 'node:path';
import { createRequire } from 'node:module';
import { pathToFileURL } from 'node:url';
const bundledRequire = createRequire(process.argv[2]);
const { Workbook } = await import(pathToFileURL(bundledRequire.resolve('@oai/artifact-tool')).href);
const expected = {
  movies: [2029, 'Gross', 48722950, 37025261],
  live: [66, 'Gross', 2515250, 3308748],
  rentals: [19, 'Invoice', 1020600, 742774],
  legacy: [815, 'Ticket Gross', 102475375, 56346050],
};
for (const file of process.argv.slice(3)) {
  const dataset = /^roxy-grosses-(movies|live|rentals|legacy)-/.exec(path.basename(file))?.[1];
  if (!dataset) throw Error('Unexpected report filename');
  const workbook = await Workbook.fromCSV(await fs.readFile(file, 'utf8'), { sheetName: 'Report' });
  const values = workbook.worksheets.getItem('Report').getUsedRange().values;
  const header = values[0];
  const rows = values.slice(1);
  const [count, grossLabel, grossCents, concessionsCents] = expected[dataset];
  const total = label => {
    const column = header.indexOf(label);
    if (column < 0) throw Error('Missing financial column');
    return rows.reduce((sum, row) => {
      if (!/^\$-?\d+\.\d{2}$/.test(row[column])) throw Error('Unexpected currency value');
      return sum + Math.round(Number(row[column].slice(1)) * 100);
    }, 0);
  };
  if (rows.length !== count || total(grossLabel) !== grossCents || total('Concessions') !== concessionsCents) throw Error(`${dataset} download does not match live source counts/totals`);
  console.log(`PASS: ${dataset} actual browser download, ${count} rows, exact gross/concessions totals match live dashboard`);
}
