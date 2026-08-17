import type { SandIamFormField } from './types'

export const statusField: SandIamFormField = { key: 'status', label: 'status', kind: 'status' }

export const organizationFields: SandIamFormField[] = [
  { key: 'code', label: 'code', kind: 'text', required: true, createOnly: true },
  { key: 'name', label: 'name', kind: 'text', required: true },
  statusField
]

export const applicationFields: SandIamFormField[] = [
  { key: 'organization_id', label: 'organization_id', kind: 'number', required: true },
  { key: 'code', label: 'code', kind: 'text', required: true, createOnly: true },
  { key: 'name', label: 'name', kind: 'text', required: true },
  statusField
]

export const environmentFields: SandIamFormField[] = [
  { key: 'application_id', label: 'application_id', kind: 'number', required: true },
  { key: 'code', label: 'code', kind: 'text', required: true, createOnly: true },
  { key: 'name', label: 'name', kind: 'text', required: true },
  statusField
]

export const clientFields: SandIamFormField[] = [
  { key: 'environment_id', label: 'environment_id', kind: 'number', required: true },
  { key: 'code', label: 'code', kind: 'text', required: true, createOnly: true },
  { key: 'name', label: 'name', kind: 'text', required: true },
  { key: 'audience', label: 'audience', kind: 'text', required: true },
  statusField
]

export const grantFields: SandIamFormField[] = [
  { key: 'workload_client_id', label: 'workload_client_id', kind: 'number', required: true },
  { key: 'service_action_id', label: 'service_action_id', kind: 'number', required: true },
  { key: 'audience', label: 'audience', kind: 'text', required: true },
  { key: 'quota_policy', label: 'quota_policy', kind: 'json' },
  { key: 'data_class', label: 'data_class', kind: 'text' },
  { key: 'network_policy', label: 'network_policy', kind: 'json' },
  { key: 'expire_time', label: 'expire_time', kind: 'datetime' },
  statusField
]

export const identityFields: SandIamFormField[] = [
  { key: 'application_id', label: 'application_id', kind: 'number', required: true },
  { key: 'code', label: 'code', kind: 'text', required: true, createOnly: true },
  { key: 'display_name', label: 'display_name', kind: 'text', required: true },
  statusField
]

export const identityBindingFields: SandIamFormField[] = [
  { key: 'identity_id', label: 'identity_id', kind: 'number', required: true, createOnly: true },
  { key: 'provider_code', label: 'provider_code', kind: 'text', required: true, createOnly: true },
  { key: 'subject', label: 'subject', kind: 'text', required: true, createOnly: true },
  statusField
]

export const namedAppFields: SandIamFormField[] = [
  { key: 'application_id', label: 'application_id', kind: 'number', required: true },
  { key: 'code', label: 'code', kind: 'text', required: true, createOnly: true },
  { key: 'name', label: 'name', kind: 'text', required: true },
  statusField
]

export const resourceFields: SandIamFormField[] = [
  { key: 'application_id', label: 'application_id', kind: 'number', required: true },
  { key: 'code', label: 'code', kind: 'text', required: true, createOnly: true },
  { key: 'name', label: 'name', kind: 'text', required: true },
  { key: 'owner_field', label: 'owner_field', kind: 'text' },
  { key: 'organization_field', label: 'organization_field', kind: 'text' },
  statusField
]

export const policyFields: SandIamFormField[] = [
  { key: 'application_id', label: 'application_id', kind: 'number', required: true },
  { key: 'resource_id', label: 'resource_id', kind: 'number', required: true },
  { key: 'role_id', label: 'role_id', kind: 'number' },
  { key: 'identity_id', label: 'identity_id', kind: 'number' },
  { key: 'action', label: 'action', kind: 'text', required: true },
  { key: 'effect', label: 'effect', kind: 'select', required: true, options: [
    { label: 'allow', value: 'allow' },
    { label: 'deny', value: 'deny' }
  ] },
  { key: 'condition', label: 'condition', kind: 'json' },
  { key: 'scope', label: 'scope', kind: 'json' },
  { key: 'priority', label: 'priority', kind: 'number' },
  { key: 'state', label: 'state', kind: 'select', options: [
    { label: 'draft', value: 'draft' },
    { label: 'published', value: 'published' },
    { label: 'revoked', value: 'revoked' }
  ] },
  statusField
]

export const adminGrantFields: SandIamFormField[] = [
  { key: 'admin_user_id', label: 'admin_user_id', kind: 'number', required: true },
  { key: 'organization_id', label: 'organization_id', kind: 'number', required: true },
  statusField
]

export const serviceFields: SandIamFormField[] = organizationFields

export const actionFields: SandIamFormField[] = [
  { key: 'service_id', label: 'service_id', kind: 'number', required: true },
  { key: 'code', label: 'code', kind: 'text', required: true, createOnly: true },
  { key: 'name', label: 'name', kind: 'text', required: true },
  statusField
]

export const credentialFields: SandIamFormField[] = [
  { key: 'workload_client_id', label: 'workload_client_id', kind: 'number', required: true },
  { key: 'name', label: 'name', kind: 'text', required: true },
  { key: 'expire_time', label: 'expire_time', kind: 'datetime' }
]
