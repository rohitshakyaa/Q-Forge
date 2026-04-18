<script setup lang="ts">
import { ref } from 'vue'
import axios from 'axios'

const healthStatus = ref<string>('')
const pythonStatus = ref<string>('')

const apiBaseUrl = import.meta.env.VITE_API_BASE_URL || '/api'

const checkHealth = async () => {
  try {
    const response = await axios.get(`${apiBaseUrl}/health`)
    healthStatus.value = `API Health: ${response.data.status}`
  } catch (error) {
    healthStatus.value = 'API Health: Error'
  }
}

const testPython = async () => {
  try {
    const response = await axios.get(`${apiBaseUrl}/test-python`)
    if (response.data.status === 'success') {
      pythonStatus.value = `Python Health: ${response.data.python_response.status}`
    } else {
      pythonStatus.value = `Python Health: Error - ${response.data.message}`
    }
  } catch (error) {
    pythonStatus.value = 'Python Health: Error'
  }
}
</script>

<template>
  <div>
    <h1>Q-Forge Frontend</h1>
    <button @click="checkHealth">Test API Health</button>
    <p v-if="healthStatus">{{ healthStatus }}</p>
    <button @click="testPython">Test Python Endpoint</button>
    <p v-if="pythonStatus">{{ pythonStatus }}</p>
  </div>
</template>
