import { defineStore } from 'pinia'

export const useOrganStore = defineStore('sandworkflow-organ', {
  state: () => ({ depts: [], roles: [], users: [] }),
  getters: {
    getUserById: (state) => (id) => state.users.find((item) => item.id === id) || { id, name: '' },
    getDeptById: (state) => (id) => state.depts.find((item) => item.id === id) || { id, name: '' },
    getById: (state) => (id) =>
      state.depts.concat(state.roles, state.users).find((item) => item.id === id) || {
        id,
        name: '未知'
      }
  },
  actions: {
    setDepts(items) {
      this.depts = items || []
    },
    setRoles(items) {
      this.roles = items || []
    },
    setUsers(items) {
      this.users = items || []
    },
    getDepts() {
      return [...this.depts]
    },
    getRoles() {
      return [...this.roles]
    },
    getUsers() {
      return [...this.users]
    },
    getAll() {
      return { depts: this.getDepts(), roles: this.getRoles(), users: this.getUsers() }
    }
  }
})

export const useFlowStore = defineStore('sandworkflow-flow', {
  state: () => ({
    flowDefinition: {},
    flowDefId: null,
    isPromoterDrawerOpened: false,
    promoterConfig0: {},
    isApproverDrawerOpened: false,
    approverConfig0: {},
    isCopyerDrawerOpened: false,
    copyerConfig0: {},
    isConditionDrawerOpened: false,
    conditionsConfig0: { conditionNodes: [] },
    isTransactorDrawerOpened: false,
    transactorConfig0: {},
    flowGroups: []
  }),
  actions: {
    setFlowDef(value) {
      this.flowDefinition = value
    },
    getFlowDef() {
      return JSON.parse(JSON.stringify(this.flowDefinition))
    },
    setFlowDefId(value) {
      this.flowDefId = value
    },
    showPromoterDrawer(value) {
      this.isPromoterDrawerOpened = value
    },
    setPromoterConfig(value) {
      this.promoterConfig0 = value
    },
    showApproverDrawer(value) {
      this.isApproverDrawerOpened = value
    },
    setApproverConfig(value) {
      this.approverConfig0 = value
    },
    showCopyerDrawer(value) {
      this.isCopyerDrawerOpened = value
    },
    setCopyerConfig(value) {
      this.copyerConfig0 = value
    },
    showConditionDrawer(value) {
      this.isConditionDrawerOpened = value
    },
    setConditionsConfig(value) {
      this.conditionsConfig0 = value
    },
    showTransactorDrawer(value) {
      this.isTransactorDrawerOpened = value
    },
    setTransactorConfig(value) {
      this.transactorConfig0 = value
    },
    setFlowGroups(value) {
      this.flowGroups = value
    }
  }
})
