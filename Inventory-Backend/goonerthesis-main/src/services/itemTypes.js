// Office Supplies item types.
//   Consumable            - used up, never returned
//   Non-Consumable Type A - issued: handed over for good, never returned
//   Non-Consumable Type B - tracked: lent out, must be returned
// Any other item_type (e.g. School Equipment's "Equipment") is tracked.
export const TYPE_CONSUMABLE = "Consumable";
export const TYPE_A = "Non-Consumable Type A";
export const TYPE_B = "Non-Consumable Type B";

export const OFFICE_TYPE_OPTIONS = [
  { value: TYPE_CONSUMABLE, short: "Consumable", label: "Consumable (used up)" },
  { value: TYPE_A, short: "Type A", label: "Non-Consumable Type A (issued, not returned)" },
  { value: TYPE_B, short: "Type B", label: "Non-Consumable Type B (tracked, must be returned)" },
];

// Handed over for good: never returned, never "borrowed".
export function isIssuedType(type) {
  return type === TYPE_CONSUMABLE || type === TYPE_A;
}

// Lent out and expected back.
export function isReturnableType(type) {
  return !isIssuedType(type);
}
