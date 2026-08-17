<template>
  <div v-if="widgets.length" class="flow-form-detail">
    <template v-for="widget in widgets" :key="widget.name">
      <div v-if="widget.type === WidgetType.Describe" class="form-item form-item--describe">
        <div class="label">{{ widget.label }}</div>
        <div class="value muted">{{ asText(values[widget.name]) || '说明' }}</div>
      </div>

      <div v-else-if="isPlainTextType(widget.type)" class="form-item">
        <div class="label">{{ widget.label }}</div>
        <div class="value">{{ formatPlain(values[widget.name], widget.type) }}</div>
      </div>

      <div v-else-if="widget.type === WidgetType.Multiline" class="form-item">
        <div class="label">{{ widget.label }}</div>
        <div class="value pre-wrap">{{ asText(values[widget.name]) || '-' }}</div>
      </div>

      <div v-else-if="widget.type === WidgetType.RichText" class="form-item">
        <div class="label">{{ widget.label }}</div>
        <div class="value rich-text" v-html="asSafeHtml(values[widget.name])" />
      </div>

      <div v-else-if="widget.type === WidgetType.Website" class="form-item">
        <div class="label">{{ widget.label }}</div>
        <div class="value">
          <a
            v-if="asText(values[widget.name])"
            class="link"
            :href="asText(values[widget.name])"
            target="_blank"
            rel="noopener noreferrer"
          >
            {{ asText(values[widget.name]) }}
          </a>
          <span v-else>-</span>
        </div>
      </div>

      <div v-else-if="widget.type === WidgetType.Rate" class="form-item">
        <div class="label">{{ widget.label }}</div>
        <div class="value">
          <ElRate :model-value="asNumber(values[widget.name])" disabled />
        </div>
      </div>

      <div v-else-if="widget.type === WidgetType.Picture" class="form-item">
        <div class="label">{{ widget.label }}</div>
        <div class="value image-list">
          <template v-if="asMediaList(values[widget.name]).length">
            <a
              v-for="(item, index) in asMediaList(values[widget.name])"
              :key="`${widget.name}-${index}`"
              class="image-chip"
              :href="item.url || undefined"
              :target="item.url ? '_blank' : undefined"
              rel="noopener noreferrer"
            >
              <img v-if="item.url" :src="item.url" :alt="item.name" />
              <span v-else>{{ item.name }}</span>
            </a>
          </template>
          <span v-else class="muted">-</span>
        </div>
      </div>

      <div v-else-if="widget.type === WidgetType.Attachment" class="form-item">
        <div class="label">{{ widget.label }}</div>
        <div class="value attachment-list">
          <template v-if="asMediaList(values[widget.name]).length">
            <a
              v-for="(item, index) in asMediaList(values[widget.name])"
              :key="`${widget.name}-file-${index}`"
              class="link"
              :href="item.url || undefined"
              :target="item.url ? '_blank' : undefined"
              rel="noopener noreferrer"
            >
              {{ item.name }}
            </a>
          </template>
          <span v-else class="muted">-</span>
        </div>
      </div>

      <div v-else-if="widget.type === WidgetType.FlowInstance" class="form-item">
        <div class="label">{{ widget.label }}</div>
        <div class="value">{{ formatPlain(values[widget.name], widget.type) }}</div>
      </div>

      <div v-else-if="widget.type === WidgetType.Detail" class="form-item form-item--detail">
        <div class="label">{{ widget.label }}</div>
        <div class="value">
          <ElTable
            v-if="asRows(values[widget.name]).length && detailColumns(widget).length"
            :data="asRows(values[widget.name])"
            size="small"
            border
          >
            <ElTableColumn
              v-for="column in detailColumns(widget)"
              :key="column.name"
              :prop="column.name"
              :label="column.label"
              min-width="100"
            >
              <template #default="{ row }">
                <template v-if="column.type === WidgetType.Rate">
                  <ElRate :model-value="asNumber(rowValue(row, column.name))" disabled />
                </template>
                <template v-else-if="column.type === WidgetType.Website">
                  <a
                    v-if="asText(rowValue(row, column.name))"
                    class="link"
                    :href="asText(rowValue(row, column.name))"
                    target="_blank"
                    rel="noopener noreferrer"
                  >
                    {{ asText(rowValue(row, column.name)) }}
                  </a>
                  <span v-else>-</span>
                </template>
                <template v-else-if="column.type === WidgetType.Multiline">
                  <span class="pre-wrap">{{ asText(rowValue(row, column.name)) || '-' }}</span>
                </template>
                <template v-else>
                  {{ formatPlain(rowValue(row, column.name), column.type) }}
                </template>
              </template>
            </ElTableColumn>
          </ElTable>
          <span v-else class="muted">暂无明细</span>
        </div>
      </div>

      <div v-else class="form-item">
        <div class="label">{{ widget.label }}</div>
        <div class="value">{{ formatPlain(values[widget.name], widget.type) }}</div>
      </div>
    </template>
  </div>
  <ElEmpty v-else description="暂无可读表单字段" :image-size="64" />
</template>

<script setup lang="ts">
  import { computed } from 'vue'

  enum WidgetType {
    SingleLine = 0,
    Multiline = 1,
    Describe = 2,
    Number = 3,
    Money = 4,
    SingleChoice = 5,
    MultiChoice = 6,
    Date = 7,
    DateRange = 8,
    Detail = 9,
    Picture = 10,
    Attachment = 11,
    Department = 12,
    Employee = 13,
    FlowInstance = 15,
    Area = 16,
    Mailbox = 17,
    Mobile = 18,
    IdCard = 19,
    Website = 20,
    Rate = 21,
    RichText = 22,
    Formula = 23
  }

  interface FormDetailWidget {
    name: string
    label: string
    type: number
    details?: FormDetailWidget[]
  }

  interface MediaItem {
    name: string
    url?: string
  }

  const props = withDefaults(
    defineProps<{
      widgets?: FormDetailWidget[]
      formValue?: Record<string, unknown>
    }>(),
    {
      widgets: () => [],
      formValue: () => ({})
    }
  )

  const widgets = computed(() => props.widgets)
  const values = computed(() => props.formValue)

  function isPlainTextType(type: number): boolean {
    return [
      WidgetType.SingleLine,
      WidgetType.Number,
      WidgetType.Money,
      WidgetType.SingleChoice,
      WidgetType.MultiChoice,
      WidgetType.Date,
      WidgetType.DateRange,
      WidgetType.Department,
      WidgetType.Employee,
      WidgetType.Area,
      WidgetType.Mailbox,
      WidgetType.Mobile,
      WidgetType.IdCard,
      WidgetType.Formula
    ].includes(type)
  }

  function detailColumns(widget: FormDetailWidget): FormDetailWidget[] {
    return (widget.details || []).filter((item) => item.type !== WidgetType.Describe)
  }

  function rowValue(row: Record<string, unknown>, key: string): unknown {
    return row[key]
  }

  function asText(value: unknown): string {
    if (value === undefined || value === null) return ''
    if (typeof value === 'string') return value
    if (typeof value === 'number' || typeof value === 'boolean') return String(value)
    return ''
  }

  function asNumber(value: unknown): number {
    if (typeof value === 'number' && Number.isFinite(value)) return value
    if (typeof value === 'string' && value.trim() !== '' && !Number.isNaN(Number(value))) {
      return Number(value)
    }
    return 0
  }

  function asRows(value: unknown): Record<string, unknown>[] {
    if (!Array.isArray(value)) return []
    return value.filter(isRecord)
  }

  function asMediaList(value: unknown): MediaItem[] {
    if (!Array.isArray(value)) return []
    return value
      .map((item): MediaItem | null => {
        if (typeof item === 'string') {
          const text = item.trim()
          if (!text) return null
          if (/^https?:\/\//i.test(text) || text.startsWith('/')) {
            return { name: text.split('/').pop() || text, url: text }
          }
          return { name: text }
        }
        if (!isRecord(item)) return null
        const name =
          asText(item.name) ||
          asText(item.fileName) ||
          asText(item.filename) ||
          asText(item.id) ||
          '附件'
        const url = asText(item.url) || asText(item.path) || asText(item.src) || undefined
        return { name, url }
      })
      .filter((item): item is MediaItem => item !== null)
  }

  function formatPlain(value: unknown, widgetType?: number): string {
    if (value === undefined || value === null || value === '') return '-'
    if (Array.isArray(value)) {
      const parts = value.map((item) => {
        if (isRecord(item)) {
          return asText(item.name) || asText(item.label) || asText(item.id) || JSON.stringify(item)
        }
        return String(item)
      })
      return parts.join(widgetType === WidgetType.DateRange ? ' 至 ' : '、') || '-'
    }
    if (widgetType === WidgetType.Money && (typeof value === 'number' || isNumeric(value))) {
      return Number(value).toLocaleString('zh-CN', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2
      })
    }
    if (isRecord(value)) {
      return asText(value.name) || asText(value.label) || JSON.stringify(value)
    }
    return String(value)
  }

  function asSafeHtml(value: unknown): string {
    return asText(value) || '-'
  }

  function isNumeric(value: unknown): value is string {
    return typeof value === 'string' && value.trim() !== '' && !Number.isNaN(Number(value))
  }

  function isRecord(value: unknown): value is Record<string, unknown> {
    return typeof value === 'object' && value !== null && !Array.isArray(value)
  }
</script>

<style scoped>
  .flow-form-detail {
    display: grid;
    gap: 14px 0;
  }

  .form-item {
    display: grid;
    grid-template-columns: 110px minmax(0, 1fr);
    gap: 8px 16px;
    align-items: start;
  }

  .form-item--detail {
    grid-template-columns: 1fr;
  }

  .label {
    color: var(--el-text-color-secondary);
    font-size: 13px;
    line-height: 1.6;
  }

  .value {
    min-width: 0;
    color: var(--el-text-color-primary);
    font-size: 13px;
    line-height: 1.6;
    word-break: break-word;
  }

  .pre-wrap {
    white-space: pre-wrap;
  }

  .muted {
    color: var(--el-text-color-placeholder);
  }

  .link {
    color: var(--el-color-primary);
    text-decoration: none;
  }

  .link + .link {
    margin-left: 10px;
  }

  .image-list {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
  }

  .image-chip {
    display: inline-flex;
    overflow: hidden;
    max-width: 120px;
    border: 1px solid var(--el-border-color-lighter);
    border-radius: 6px;
  }

  .image-chip img {
    display: block;
    width: 72px;
    height: 72px;
    object-fit: cover;
  }

  .image-chip span {
    padding: 8px 10px;
    font-size: 12px;
  }

  .attachment-list {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
  }

  .rich-text :deep(img) {
    max-width: 100%;
  }

  @media (max-width: 720px) {
    .form-item {
      grid-template-columns: 90px minmax(0, 1fr);
    }
  }
</style>
