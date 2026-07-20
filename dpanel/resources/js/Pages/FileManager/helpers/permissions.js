export function getPermissionPreview(permissionValue) {
    const digits = String(permissionValue || '').replace(/^0+/, '').slice(0, 3);
    if (digits.length !== 3) return null;

    const octToBin = (oct) => (+oct).toString(2).padStart(3, '0');
    const owner = octToBin(digits[0]);
    const group = octToBin(digits[1]);
    const world = octToBin(digits[2]);

    const toSym = (bin) => (bin[0] === '1' ? 'r' : '-') + (bin[1] === '1' ? 'w' : '-') + (bin[2] === '1' ? 'x' : '-');
    const symbolic = toSym(owner) + toSym(group) + toSym(world);
    const dangerous = digits[2] === '7' || digits === '777';

    return {
        display: `0${digits}`,
        symbolic,
        owner: { read: owner[0] === '1', write: owner[1] === '1', execute: owner[2] === '1' },
        group: { read: group[0] === '1', write: group[1] === '1', execute: group[2] === '1' },
        world: { read: world[0] === '1', write: world[1] === '1', execute: world[2] === '1' },
        dangerous,
    };
}
