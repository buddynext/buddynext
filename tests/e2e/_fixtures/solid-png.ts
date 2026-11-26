import { deflateSync } from 'zlib';

/** CRC-32 of a buffer (the PNG chunk checksum). */
function crc32(buf: Buffer): number {
    let c = ~0;
    for (const byte of buf) {
        c ^= byte;
        for (let k = 0; k < 8; k++) {
            c = c & 1 ? (c >>> 1) ^ 0xedb88320 : c >>> 1;
        }
    }
    return ~c >>> 0;
}

function chunk(type: string, data: Buffer): Buffer {
    const body = Buffer.concat([Buffer.from(type, 'ascii'), data]);
    const out = Buffer.alloc(8 + data.length + 4);
    out.writeUInt32BE(data.length, 0);
    body.copy(out, 4);
    out.writeUInt32BE(crc32(body), 4 + body.length);
    return out;
}

/**
 * A solid-colour PNG of an exact size, for upload tests. Built here so the suite ships
 * no binary fixtures; deflate keeps even 1600x900 to a few KB.
 */
export function solidPng(width: number, height: number, rgb: [number, number, number] = [60, 120, 200]): { name: string; mimeType: string; buffer: Buffer } {
    const header = Buffer.alloc(13);
    header.writeUInt32BE(width, 0);
    header.writeUInt32BE(height, 4);
    header[8] = 8; // bit depth
    header[9] = 2; // truecolour RGB
    const row = Buffer.concat([Buffer.from([0]), Buffer.from(Array.from({ length: width }, () => rgb).flat())]);
    const raw = Buffer.concat(Array.from({ length: height }, () => row));
    const png = Buffer.concat([Buffer.from([0x89, 0x50, 0x4e, 0x47, 0x0d, 0x0a, 0x1a, 0x0a]), chunk('IHDR', header), chunk('IDAT', deflateSync(raw)), chunk('IEND', Buffer.alloc(0))]);
    return { name: `solid-${width}x${height}.png`, mimeType: 'image/png', buffer: png };
}
