<!-- Fixed bottom action bar for slider and placement layouts (Save / Cancel ...). -->
<template>
  <div class="fixed inset-x-0 bottom-0 z-10 light bg-(--popup-window-background-color) flex items-center justify-center border-t-1 border-t-(--ui-color-divider-less) shadow-top-md py-[9px] px-2 pr-(--scrollbar-width)">
    <div class="flex flex-row gap-[10px]">
      <slot />
    </div>
  </div>
</template>
