<script setup lang="ts">
import { ref, reactive, onMounted, computed } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { DataService } from '@/config/dataService/dataService';
import { notification, Modal } from 'ant-design-vue';
import { Main } from '../styled';
import { groupTitle, groupDescriptions } from './permissionGroupMeta';

interface PermissionRule {
  key: string;
  description: string | null;
  logic_group: string | null;
}

const route = useRoute();
const router = useRouter();
const editingId = computed(() => (route.params.id ? Number(route.params.id) : null));
const isEdit = computed(() => editingId.value !== null);
const isBuiltIn = computed(() => isEdit.value && (editingId.value as number) < 0);

const loading = ref(true);
const saving = ref(false);
const removing = ref(false);
const allPermissions = ref<PermissionRule[]>([]);

const form = reactive({
  name: '',
  description: '',
  display: true,
  permissions: [] as string[],
});

// Two-column layout of permission groups, in the same order the real
// "Create new role" page shows them (API return order), each group's
// checkboxes labelled with that permission's own real `description` — the
// group titles/subtitles aren't in the API at all, see permissionGroupMeta.ts.
const groupedPermissions = computed(() => {
  const byGroup = new Map<string, PermissionRule[]>();
  for (const p of allPermissions.value) {
    const key = p.logic_group || '(none)';
    if (!byGroup.has(key)) byGroup.set(key, []);
    byGroup.get(key)!.push(p);
  }
  return Array.from(byGroup.entries()).map(([key, perms]) => ({ key, title: groupTitle(key), description: groupDescriptions[key], perms }));
});
const leftColumn = computed(() => groupedPermissions.value.filter((_, i) => i % 2 === 0));
const rightColumn = computed(() => groupedPermissions.value.filter((_, i) => i % 2 === 1));

function isGroupFullyChecked(perms: PermissionRule[]) {
  return perms.every((p) => form.permissions.includes(p.key));
}
function isGroupPartiallyChecked(perms: PermissionRule[]) {
  const checked = perms.filter((p) => form.permissions.includes(p.key)).length;
  return checked > 0 && checked < perms.length;
}
function toggleGroup(perms: PermissionRule[], checked: boolean) {
  const keys = perms.map((p) => p.key);
  if (checked) {
    form.permissions = Array.from(new Set([...form.permissions, ...keys]));
  } else {
    form.permissions = form.permissions.filter((k) => !keys.includes(k));
  }
}
function isChecked(key: string) {
  return form.permissions.includes(key);
}
function togglePermission(key: string, checked: boolean) {
  if (checked) {
    if (!form.permissions.includes(key)) form.permissions.push(key);
  } else {
    form.permissions = form.permissions.filter((k) => k !== key);
  }
}

async function load() {
  loading.value = true;
  const permsRes = await DataService.get('/user-role-permissions').catch(() => null);
  if (permsRes) allPermissions.value = permsRes.data.data || [];

  if (isEdit.value) {
    try {
      const { data } = await DataService.get(`/user-role/${editingId.value}`);
      const r = data.data;
      Object.assign(form, {
        name: r.name,
        description: r.description || '',
        display: r.display,
        permissions: r.permissions || [],
      });
    } catch (err: any) {
      notification.error({
        message: 'Could not load role',
        description: err?.response?.data?.error?.description || 'Please try again.',
      });
    }
  }
  loading.value = false;
}
onMounted(load);

async function submit() {
  if (isBuiltIn.value) return;
  if (!form.name.trim()) {
    notification.error({ message: 'Name is required' });
    return;
  }
  saving.value = true;
  const payload = {
    name: form.name,
    display: form.display,
    description: form.description || undefined,
    permissions: form.permissions,
  };
  try {
    if (isEdit.value) {
      await DataService.put(`/user-role/${editingId.value}`, payload);
      notification.success({ message: 'Role updated' });
    } else {
      await DataService.post('/user-role', payload);
      notification.success({ message: 'Role created' });
    }
    router.push({ name: 'user-roles' });
  } catch (err: any) {
    notification.error({
      message: 'Could not save role',
      description: err?.response?.data?.error?.description || 'Please try again.',
    });
  } finally {
    saving.value = false;
  }
}

function confirmRemove() {
  if (!editingId.value || isBuiltIn.value) return;
  Modal.confirm({
    title: `Delete role "${form.name}"?`,
    okText: 'Delete',
    okType: 'danger',
    onOk: async () => {
      removing.value = true;
      try {
        await DataService.delete(`/user-role/${editingId.value}`);
        notification.success({ message: 'Role deleted' });
        router.push({ name: 'user-roles' });
      } catch (err: any) {
        notification.error({
          message: 'Could not delete role',
          description: err?.response?.data?.error?.description || 'Please try again.',
        });
      } finally {
        removing.value = false;
      }
    },
  });
}

const pageTitle = computed(() => (isEdit.value ? 'Edit role' : 'Create new role'));
const rolesBreadcrumb = "User's roles";
</script>

<template>
  <sdPageHeader
    :routes="[{ path: '/', breadcrumbName: 'Dashboard' }, { path: '/management/user-role', breadcrumbName: rolesBreadcrumb }, { path: '', breadcrumbName: pageTitle }]"
    :title="pageTitle"
    class="ninjadash-page-header-main"
  >
    <template #buttons>
      <div class="role-form-actions">
        <sdButton type="light" @click="router.push({ name: 'user-roles' })"><unicon name="arrow-left"></unicon> Back</sdButton>
        <sdButton v-if="!isBuiltIn" type="primary" :loading="saving" @click="submit"><unicon name="save"></unicon> {{ isEdit ? 'Save' : 'Create' }}</sdButton>
        <sdButton v-if="isEdit && !isBuiltIn" type="danger" :loading="removing" @click="confirmRemove"><unicon name="trash-alt"></unicon> Delete</sdButton>
      </div>
    </template>
  </sdPageHeader>
  <Main>
    <a-skeleton v-if="loading" active />
    <template v-else>
      <sdCards :headless="true" style="margin-bottom: 20px">
        <div v-if="isBuiltIn" class="role-builtin-note">
          <unicon name="lock"></unicon> This is a built-in role. Its name and permissions can't be changed.
        </div>
        <a-row :gutter="16">
          <a-col :xs="24" :md="8">
            <label>Name</label>
            <a-input v-model:value="form.name" placeholder="Enter name" :disabled="isBuiltIn" />
          </a-col>
          <a-col :xs="24" :md="12">
            <label>Description</label>
            <a-input v-model:value="form.description" placeholder="Enter description" :disabled="isBuiltIn" />
          </a-col>
          <a-col :xs="24" :md="4">
            <label>Display</label>
            <div><a-switch v-model:checked="form.display" :disabled="isBuiltIn" /></div>
          </a-col>
        </a-row>
      </sdCards>

      <sdCards title="Role privileges">
        <a-row :gutter="25">
          <a-col v-for="col in [leftColumn, rightColumn]" :key="col === leftColumn ? 'l' : 'r'" :xs="24" :md="12">
            <div v-for="g in col" :key="g.key" class="perm-group">
              <div class="perm-group__header" :class="{ 'perm-group__header--partial': isGroupPartiallyChecked(g.perms) }">
                <a-switch
                  :checked="isGroupFullyChecked(g.perms)"
                  :disabled="isBuiltIn"
                  @change="(checked: boolean) => toggleGroup(g.perms, checked)"
                />
                <div class="perm-group__title-block">
                  <strong>{{ g.title }}</strong>
                  <p v-if="g.description" class="perm-group__desc">{{ g.description }}</p>
                </div>
              </div>
              <div class="perm-group__list">
                <div v-for="p in g.perms" :key="p.key" class="perm-item">
                  <span class="perm-item__label">{{ p.description || p.key }}</span>
                  <a-switch
                    size="small"
                    :checked="isChecked(p.key)"
                    :disabled="isBuiltIn"
                    @change="(checked: boolean) => togglePermission(p.key, checked)"
                  />
                </div>
              </div>
            </div>
          </a-col>
        </a-row>
      </sdCards>
    </template>
  </Main>
</template>

<style scoped>
.role-form-actions {
  display: flex;
  gap: 10px;
}
.role-form-actions :deep(svg) {
  width: 13px;
  height: 13px;
  margin-right: 4px;
}
.role-builtin-note {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 10px 14px;
  margin-bottom: 16px;
  border-radius: 6px;
  background: #fff8e6;
  color: #92600a;
  font-size: 13px;
  font-weight: 600;
}
.role-builtin-note :deep(svg) {
  width: 14px;
  height: 14px;
}
label {
  display: block;
  font-size: 12px;
  font-weight: 700;
  color: #272b41;
  margin-bottom: 6px;
}
.perm-group {
  margin-bottom: 26px;
  border: 1px solid #eceef4;
  border-radius: 10px;
  padding: 14px 16px;
  background: #fbfbfd;
}
.perm-group__header {
  display: flex;
  align-items: flex-start;
  gap: 12px;
  padding-bottom: 12px;
  margin-bottom: 10px;
  border-bottom: 1px solid #eceef4;
}
.perm-group__header--partial :deep(.ant-switch) {
  background: linear-gradient(90deg, #1868db 50%, #dde1ec 50%);
}
.perm-group__title-block strong {
  font-size: 14px;
  color: #272b41;
}
.perm-group__desc {
  font-size: 11px;
  color: #8c90a4;
  margin: 4px 0 0;
}
.perm-group__list {
  display: flex;
  flex-direction: column;
  gap: 2px;
}
.perm-item {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  padding: 7px 4px;
  border-radius: 6px;
  font-size: 13px;
  color: #5a5f7d;
  transition: background 0.15s ease;
}
.perm-item:hover {
  background: #f0f4fc;
}
.perm-item__label {
  flex: 1;
}
</style>
