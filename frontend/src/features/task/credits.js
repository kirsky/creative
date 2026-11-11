/**
 * The sum of credit amounts written as the API writes them ("120.00"), exact: cents are added as
 * whole numbers, no float arithmetic on decimals.
 * @param {string[]} amounts decimal strings with two decimals
 * @returns {string} a decimal string with two decimals; amounts that cannot be read count as zero
 */
export function sumCredits(amounts) {
  let cents = 0n;
  for (const amount of amounts) {
    const match = /^(\d+)\.(\d{2})$/.exec(amount);
    if (match !== null) {
      cents += BigInt(match[1]) * 100n + BigInt(match[2]);
    }
  }
  return `${cents / 100n}.${String(cents % 100n).padStart(2, '0')}`;
}
