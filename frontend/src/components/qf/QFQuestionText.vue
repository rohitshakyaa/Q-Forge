<script setup lang="ts">
import { computed } from 'vue';

/**
 * Renders a question's text, turning embedded markdown tables into real tables.
 *
 * The extractor (python-service pdf.py) renders ruled tables on digital pages as
 * markdown — "| A | 5 | | 200 |" rows with a "|---|" separator. Everything else
 * is prose. Splitting here keeps question text a plain string end to end (DB,
 * API, exports) while the UI still shows a proper grid.
 */
const props = defineProps<{ text: string }>();

type Segment = { kind: 'prose'; text: string } | { kind: 'table'; rows: string[][] };

const isTableRow = (line: string) => line.trimStart().startsWith('|');
const isSeparator = (line: string) => /^\|?[\s|:-]+\|?$/.test(line.trim()) && line.includes('-');

const parseRow = (line: string) =>
  line
    .trim()
    .replace(/^\|/, '')
    .replace(/\|$/, '')
    .split('|')
    .map((cell) => cell.trim());

const segments = computed<Segment[]>(() => {
  const out: Segment[] = [];
  let prose: string[] = [];
  let rows: string[][] = [];

  const flushProse = () => {
    if (prose.length > 0) out.push({ kind: 'prose', text: prose.join('\n') });
    prose = [];
  };
  const flushTable = () => {
    if (rows.length > 0) out.push({ kind: 'table', rows });
    rows = [];
  };

  for (const line of props.text.split('\n')) {
    if (isTableRow(line)) {
      flushProse();
      if (!isSeparator(line)) rows.push(parseRow(line));
    } else {
      flushTable();
      prose.push(line);
    }
  }
  flushProse();
  flushTable();

  return out;
});
</script>

<template>
  <div>
    <template v-for="(seg, i) in segments" :key="i">
      <p v-if="seg.kind === 'prose'" class="qf-qt-prose">{{ seg.text }}</p>
      <table v-else class="qf-qt-table">
        <tbody>
          <tr v-for="(row, r) in seg.rows" :key="r">
            <component :is="r === 0 ? 'th' : 'td'" v-for="(cell, c) in row" :key="c">
              {{ cell }}
            </component>
          </tr>
        </tbody>
      </table>
    </template>
  </div>
</template>

<style scoped>
.qf-qt-prose {
  white-space: pre-line;
  margin: 0;
}
.qf-qt-prose + .qf-qt-prose {
  margin-top: 6px;
}
.qf-qt-table {
  border-collapse: collapse;
  margin: 8px 0;
  font-size: 0.95em;
}
.qf-qt-table th,
.qf-qt-table td {
  border: 1px solid var(--border);
  padding: 4px 10px;
  text-align: left;
}
.qf-qt-table th {
  background: var(--bg2);
  font-weight: 600;
}
</style>
