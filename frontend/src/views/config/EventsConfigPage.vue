<script setup lang="ts">
import { ref, onMounted } from 'vue';
import { DataService } from '@/config/dataService/dataService';
import { notification, Modal } from 'ant-design-vue';
import { Main } from '../styled';

interface AlertRule {
  id?: number;
  enabled: boolean;
  internal: boolean;
  group_name: string;
  alert_name: string;
  expression: string;
  for: string;
  severity: string;
  annotation_summary: string;
  annotation_description: string;
}

const loading = ref(true);
const saving = ref(false);
const rules = ref<AlertRule[]>([]);
const validatingIndex = ref<number | null>(null);
const ruleErrors = ref<Record<number, string>>({});

async function load() {
  loading.value = true;
  try {
    const { data } = await DataService.get('/component/events/alertmanager');
    rules.value = data.data || [];
  } finally {
    loading.value = false;
  }
}
onMounted(load);

function addRule() {
  rules.value.unshift({
    enabled: true,
    internal: false,
    group_name: '',
    alert_name: '',
    expression: '',
    for: '5m',
    severity: 'warning',
    annotation_summary: '',
    annotation_description: '',
  });
}

function confirmDelete(i: number) {
  const rule = rules.value[i];
  Modal.confirm({
    title: `Delete rule "${rule.alert_name || '(unnamed)'}"?`,
    okText: 'Delete',
    okType: 'danger',
    onOk: () => {
      rules.value.splice(i, 1);
    },
  });
}

function rulePayload(r: AlertRule) {
  return {
    enabled: r.enabled,
    group_name: r.group_name,
    alert_name: r.alert_name,
    expression: r.expression,
    for: r.for,
    severity: r.severity,
    annotation_summary: r.annotation_summary,
    annotation_description: r.annotation_description,
  };
}

// The backend exposes real per-rule validation (syntax-checks the PromQL
// expression and the rule object as a whole) — use it both as an on-demand
// "Validate" button per card and as a pre-flight check before Save, so a
// typo in one rule surfaces as a specific inline error instead of a single
// opaque 400 for the whole batch.
async function validateOne(i: number): Promise<boolean> {
  const r = rules.value[i];
  if (r.internal) return true;
  validatingIndex.value = i;
  try {
    await DataService.put('/component/events/alertmanager/validate-rule', rulePayload(r));
    delete ruleErrors.value[i];
    return true;
  } catch (err: any) {
    ruleErrors.value[i] = err?.response?.data?.error?.description || err?.response?.data?.message || 'Invalid rule';
    return false;
  } finally {
    validatingIndex.value = null;
  }
}
async function validateAndNotify(i: number) {
  const ok = await validateOne(i);
  if (ok) notification.success({ message: 'Rule is valid' });
}

async function save() {
  for (const r of rules.value) {
    if (!r.internal && (!r.group_name || !r.alert_name || !r.expression)) {
      notification.error({ message: 'Every rule needs a group name, alert name and expression' });
      return;
    }
  }
  saving.value = true;
  ruleErrors.value = {};
  const results = await Promise.all(rules.value.map((_, i) => validateOne(i)));
  if (results.some((ok) => !ok)) {
    saving.value = false;
    notification.error({ message: 'Some rules failed validation — fix the highlighted rule(s) before saving.' });
    return;
  }
  const payload = rules.value.map(rulePayload);
  try {
    const { data } = await DataService.put('/component/events/alertmanager', payload);
    rules.value = data.data || rules.value;
    notification.success({ message: 'Alert rules saved' });
  } catch (err: any) {
    notification.error({
      message: 'Could not save rules',
      description: err?.response?.data?.error?.description || 'Please try again.',
    });
  } finally {
    saving.value = false;
  }
}

const severityMeta: Record<string, string> = { critical: 'sev-critical', warning: 'sev-warning', info: 'sev-info' };
</script>

<template>
  <sdPageHeader :routes="[{ path: '/', breadcrumbName: 'Dashboard' }, { path: '', breadcrumbName: 'Event configuration' }]" class="ninjadash-page-header-main" />
  <Main>
    <a-row :gutter="25">
      <a-col :span="24" style="margin-bottom: 16px">
        <div class="events-toolbar">
          <sdButton type="primary" :loading="saving" @click="save"><unicon name="save"></unicon> Save</sdButton>
          <sdButton type="light" @click="addRule"><unicon name="plus"></unicon> Add rule</sdButton>
        </div>
      </a-col>
    </a-row>

    <a-skeleton v-if="loading" active />
    <a-row v-else :gutter="25">
      <a-col :span="24">
        <sdCards
          v-for="(rule, i) in rules"
          :key="i"
          :headless="true"
          class="rule-card"
          :class="{ 'rule-card--internal': rule.internal, 'rule-card--invalid': ruleErrors[i] }"
        >
          <div class="rule-card__top">
            <a-switch v-model:checked="rule.enabled" :disabled="rule.internal" />
            <sdButton type="danger" size="small" :disabled="rule.internal" @click="confirmDelete(i)">
              <unicon name="trash-alt"></unicon>
            </sdButton>
            <sdButton
              v-if="!rule.internal"
              type="light"
              size="small"
              :loading="validatingIndex === i"
              @click="validateAndNotify(i)"
            >
              <unicon name="shield-check"></unicon> Validate
            </sdButton>
            <span v-if="rule.internal" class="rule-card__internal-note">
              This is an internal rule. You can't modify this parameter.
            </span>
          </div>
          <div v-if="ruleErrors[i]" class="rule-card__error">
            <unicon name="exclamation-triangle"></unicon> {{ ruleErrors[i] }}
          </div>

          <a-row :gutter="16">
            <a-col :xs="24" :md="8">
              <label>Group name</label>
              <a-input v-model:value="rule.group_name" :disabled="rule.internal" />
            </a-col>
            <a-col :xs="24" :md="8">
              <label>Alert name</label>
              <a-input v-model:value="rule.alert_name" :disabled="rule.internal" />
            </a-col>
            <a-col :xs="12" :md="4">
              <label>For</label>
              <a-input v-model:value="rule.for" :disabled="rule.internal" />
            </a-col>
            <a-col :xs="12" :md="4">
              <label>Severity</label>
              <a-select v-model:value="rule.severity" style="width: 100%" :disabled="rule.internal">
                <a-select-option value="info">info</a-select-option>
                <a-select-option value="warning">warning</a-select-option>
                <a-select-option value="critical">critical</a-select-option>
              </a-select>
            </a-col>
          </a-row>

          <div class="rule-card__field">
            <label>Expression</label>
            <a-textarea v-model:value="rule.expression" :rows="2" :disabled="rule.internal" class="rule-card__mono" />
          </div>

          <a-row :gutter="16">
            <a-col :xs="24" :md="12">
              <label>Annotation summary</label>
              <a-input v-model:value="rule.annotation_summary" :disabled="rule.internal" />
            </a-col>
            <a-col :xs="24" :md="12">
              <label>Annotation description</label>
              <a-input v-model:value="rule.annotation_description" :disabled="rule.internal" />
            </a-col>
          </a-row>

          <span class="rule-card__sev-dot" :class="severityMeta[rule.severity] || 'sev-info'"></span>
        </sdCards>
      </a-col>
    </a-row>
  </Main>
</template>

<style scoped>
.events-toolbar {
  display: flex;
  gap: 10px;
}
.events-toolbar :deep(svg) {
  width: 13px;
  height: 13px;
  margin-right: 4px;
}
.rule-card {
  position: relative;
  margin-bottom: 20px;
  border-left: 4px solid #dde1ec;
}
.rule-card--invalid {
  border-left-color: #e5484d;
}
.rule-card :deep(.ant-card-body) {
  padding: 20px 24px;
}
.rule-card__top {
  display: flex;
  align-items: center;
  gap: 12px;
  margin-bottom: 16px;
}
.rule-card__top :deep(svg) {
  width: 13px;
  height: 13px;
  margin-right: 3px;
}
.rule-card__error {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 8px 12px;
  margin-bottom: 12px;
  border-radius: 6px;
  background: rgba(255, 77, 79, 0.08);
  color: #e5484d;
  font-size: 12px;
  font-weight: 600;
}
.rule-card__error :deep(svg) {
  width: 13px;
  height: 13px;
  flex-shrink: 0;
}
.rule-card__internal-note {
  font-size: 12px;
  color: #d48806;
  font-weight: 600;
}
.rule-card--internal {
  background: #fafbfc;
}
.rule-card label {
  display: block;
  font-size: 12px;
  font-weight: 700;
  color: #8c90a4;
  margin: 10px 0 4px;
}
.rule-card__field {
  margin-top: 4px;
}
.rule-card__mono :deep(textarea) {
  font-family: monospace;
  font-size: 12px;
}
.rule-card__sev-dot {
  position: absolute;
  top: 20px;
  right: 20px;
  width: 10px;
  height: 10px;
  border-radius: 50%;
}
.sev-critical {
  background: #e5484d;
}
.sev-warning {
  background: #d48806;
}
.sev-info {
  background: #1868db;
}
</style>
