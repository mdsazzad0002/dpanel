// Fuzzy name matching for the file list, in the spirit of VS Code's quick
// open: "umc" finds UserMailController.php, and the characters that earned
// the match are the ones the list highlights.
//
// Two modes. A query holding * or ? is a glob and is matched as written;
// anything else is matched as a subsequence and scored, so the closest name
// rises to the top rather than merely being kept.

const WORD_BREAK = /[\s._\-/\\[\]()]/;

/** Boundaries score highest: a name's start, and the start of each word. */
function isBoundary(name, index) {
    if (index === 0) return true;

    const previous = name[index - 1];
    if (WORD_BREAK.test(previous)) return true;

    // camelCase: the capital that starts a word inside one.
    return previous === previous.toLowerCase() && name[index] !== name[index].toLowerCase();
}

function globToRegExp(query) {
    const pattern = query
        .split('')
        .map((char) => {
            if (char === '*') return '.*';
            if (char === '?') return '.';

            return char.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
        })
        .join('');

    try {
        return new RegExp(pattern, 'i');
    } catch {
        return null;
    }
}

/**
 * @returns {{score: number, ranges: Array<[number, number]>}|null}
 *          null when the name does not match at all.
 */
export function matchName(name, query) {
    const haystack = String(name || '');
    const needle = String(query || '').trim();

    if (!needle) return { score: 0, ranges: [] };
    if (!haystack) return null;

    if (needle.includes('*') || needle.includes('?')) {
        const pattern = globToRegExp(needle);
        const found = pattern?.exec(haystack);
        if (!found) return null;

        return {
            score: 1000 - found.index,
            ranges: found[0].length ? [[found.index, found.index + found[0].length]] : [],
        };
    }

    const lowerHay = haystack.toLowerCase();
    const lowerNeedle = needle.toLowerCase();

    // A contiguous hit always beats a scattered one, so it is tried first.
    const direct = lowerHay.indexOf(lowerNeedle);
    if (direct !== -1) {
        let score = 1000 - direct * 2;
        if (direct === 0) score += 200;
        else if (isBoundary(haystack, direct)) score += 120;
        if (haystack === needle) score += 400;
        score -= Math.max(0, haystack.length - needle.length);

        return { score, ranges: [[direct, direct + needle.length]] };
    }

    const indices = [];
    let cursor = 0;
    for (const char of lowerNeedle) {
        const found = lowerHay.indexOf(char, cursor);
        if (found === -1) return null;

        indices.push(found);
        cursor = found + 1;
    }

    let score = 0;
    let previous = -2;
    for (const index of indices) {
        score += 1;
        if (index === previous + 1) score += 8;
        if (isBoundary(haystack, index)) score += 12;
        if (index === 0) score += 15;
        // Every skipped character dilutes the match a little.
        if (previous >= 0) score -= Math.min(6, index - previous - 1);
        previous = index;
    }
    score -= Math.floor(haystack.length / 8);

    return { score, ranges: mergeRanges(indices) };
}

/** Neighbouring hit indices become one range, so highlighting stays calm. */
function mergeRanges(indices) {
    const ranges = [];

    for (const index of indices) {
        const last = ranges.at(-1);
        if (last && last[1] === index) {
            last[1] = index + 1;
            continue;
        }

        ranges.push([index, index + 1]);
    }

    return ranges;
}

/** Splits a name into plain and highlighted pieces for rendering. */
export function highlightSegments(name, ranges) {
    const text = String(name || '');
    if (!Array.isArray(ranges) || !ranges.length) return [{ text, hit: false }];

    const segments = [];
    let cursor = 0;

    for (const [start, end] of ranges) {
        if (start > cursor) segments.push({ text: text.slice(cursor, start), hit: false });
        segments.push({ text: text.slice(start, end), hit: true });
        cursor = end;
    }

    if (cursor < text.length) segments.push({ text: text.slice(cursor), hit: false });

    return segments;
}
