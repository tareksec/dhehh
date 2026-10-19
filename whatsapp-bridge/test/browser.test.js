import assert from 'node:assert/strict'
import { test } from 'node:test'
import { DEFAULT_BROWSER, describeBrowser, resolveBrowser } from '../src/browser.js'

test('defaults to the Ubuntu Desktop identity when nothing is set', () => {
  assert.equal(DEFAULT_BROWSER, 'ubuntu:Desktop')
  assert.deepEqual(resolveBrowser(undefined).slice(0, 2), ['Ubuntu', 'Desktop'])
  assert.deepEqual(resolveBrowser('').slice(0, 2), ['Ubuntu', 'Desktop'])
  assert.deepEqual(resolveBrowser('   ').slice(0, 2), ['Ubuntu', 'Desktop'])
})

test('accepts every supported system, in any letter case', () => {
  assert.deepEqual(resolveBrowser('ubuntu:Chrome').slice(0, 2), ['Ubuntu', 'Chrome'])
  assert.deepEqual(resolveBrowser('UBUNTU:Chrome').slice(0, 2), ['Ubuntu', 'Chrome'])
  assert.deepEqual(resolveBrowser('macos:Safari').slice(0, 2), ['Mac OS', 'Safari'])
  assert.deepEqual(resolveBrowser('windows:Edge').slice(0, 2), ['Windows', 'Edge'])
  assert.deepEqual(resolveBrowser('baileys:Desktop').slice(0, 2), ['Baileys', 'Desktop'])
})

test('trims spaces around the system and the browser name', () => {
  assert.deepEqual(resolveBrowser(' ubuntu : Chrome ').slice(0, 2), ['Ubuntu', 'Chrome'])
})

test('rejects values that are not "<system>:<browser>"', () => {
  for (const bad of ['Chrome', 'ubuntu', 'ubuntu:', ':Chrome', 'ubuntu:Chrome:extra', 'solaris:Chrome', 'ubuntu:Chr<ome>', 'ubuntu:9Chrome']) {
    assert.throws(() => resolveBrowser(bad), /BRIDGE_BROWSER must look like/, bad)
  }
})

test('describes an identity for logs and /health', () => {
  assert.equal(describeBrowser(resolveBrowser('ubuntu:Desktop')), 'Ubuntu / Desktop')
})
