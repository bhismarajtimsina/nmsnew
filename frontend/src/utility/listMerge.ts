import type { Ref } from 'vue';

// Small shared helpers for merging a pushed WebSocket record directly into
// a list page's local state instead of refetching the whole list. Only use
// mergeById where the pushed record's shape is close enough to the row's
// own shape (see wsClient-subscribed pages for which ones qualify) —
// Object.assign only touches fields actually present in the pushed record,
// leaving anything else already on the row (e.g. joined/computed fields)
// untouched.
export function removeById<T extends { id: number | string }>(list: Ref<T[]>, id: number | string): void {
  list.value = list.value.filter((row) => row.id !== id);
}

export function mergeById<T extends { id: number | string }>(list: Ref<T[]>, record: Partial<T> & { id: number | string }): void {
  const idx = list.value.findIndex((row) => row.id === record.id);
  if (idx === -1) {
    list.value = [...list.value, record as T];
    return;
  }
  Object.assign(list.value[idx] as object, record);
}
