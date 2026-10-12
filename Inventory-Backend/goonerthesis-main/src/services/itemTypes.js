// Office Supplies item types.
//   Consumable            - used up, never returned
//   Non-Consumable Type A - issued: handed over for good, never returned
//   Non-Consumable Type B - tracked: lent out, must be returned
// Any other item_type (e.g. School Equipment's "Equipment") is tracked.
export const TYPE_CONSUMABLE = "Consumable";
export const TYPE_A = "Non-Consumable Type A";
export const TYPE_B = "Non-Consumable Type B";

export const OFFICE_TYPE_OPTIONS = [
  { value: TYPE_CONSUMABLE, label: "Consumable (used up)" },
  { value: TYPE_A, label: "Non-Consumable Type A (issued, not returned)" },
  { value: TYPE_B, label: "Non-Consumable Type B (tracked, must be returned)" },
];

// School Equipment kinds (both are lent out and returned).
//   Fixed Asset        - projector, aircon...: can NOT have a per-requester limit
//   Limited Equipment  - accessories...: CAN have a per-requester limit
// "Equipment" is the value existing equipment already has, so it stays the
// fixed-asset value and existing rows need no change.
export const EQUIPMENT_FIXED = "Equipment";
export const EQUIPMENT_LIMITED = "Equipment (Limited)";

export const EQUIPMENT_KIND_OPTIONS = [
  { value: EQUIPMENT_FIXED, label: "Fixed Asset (no limit allowed)" },
  { value: EQUIPMENT_LIMITED, label: "Limited Equipment (can have a limit)" },
];

export function equipmentKindLabel(type) {
  return type === EQUIPMENT_LIMITED ? "Limited" : "Fixed Asset";
}

// Handed over for good: never returned, never "borrowed".
export function isIssuedType(type) {
  return type === TYPE_CONSUMABLE || type === TYPE_A;
}

// Lent out and expected back.
export function isReturnableType(type) {
  return !isIssuedType(type);
}
