

document.addEventListener('DOMContentLoaded', () => {
  // OPEN THE PROFILE EDITOR FROM THE PROFILE SUMMARY
  const profileEditor = document.getElementById('profileEditor');
  document.querySelector('[data-open-profile-editor]')?.addEventListener('click', () => {
    if (!profileEditor) return;
    if (profileEditor instanceof HTMLDetailsElement) profileEditor.open = true;
    profileEditor.scrollIntoView({ behavior: 'smooth', block: 'start' });
    const focusTarget = profileEditor.querySelector('summary') || profileEditor.querySelector('input:not([type="hidden"]), select, textarea');
    focusTarget?.focus({ preventScroll: true });
  });
  document.querySelectorAll('[data-edit-profile-field]').forEach((button) => {
    button.addEventListener('click', () => {
      const field = document.getElementById(button.dataset.editProfileField);
      if (!profileEditor || !field) return;
      profileEditor.scrollIntoView({ behavior: 'smooth', block: 'start' });
      window.setTimeout(() => field.focus({ preventScroll: true }), 350);
    });
  });
  document.querySelectorAll('[data-trigger-file]').forEach((button) => {
    button.addEventListener('click', () => {
      document.querySelectorAll('.profile-photo-menu').forEach((menu) => { menu.hidden = true; });
      document.getElementById(button.dataset.triggerFile)?.click();
    });
  });
  document.querySelectorAll('.profile-overview-avatar, .profile-cover-art').forEach((trigger) => {
    trigger.addEventListener('click', (event) => {
      if (event.target.closest('.profile-photo-menu')) return;
      event.stopPropagation();
      const menuId = trigger.classList.contains('profile-overview-avatar') ? 'profilePhotoMenu' : 'coverPhotoMenu';
      const menu = document.getElementById(menuId);
      document.querySelectorAll('.profile-photo-menu').forEach((item) => { if (item !== menu) item.hidden = true; });
      if (menu) menu.hidden = !menu.hidden;
    });
  });
  document.addEventListener('click', (event) => {
    if (!event.target.closest('.profile-overview-avatar, .profile-cover-art')) {
      document.querySelectorAll('.profile-photo-menu').forEach((menu) => { menu.hidden = true; });
    }
  });
  document.querySelectorAll('[data-show-photo]').forEach((button) => {
    button.addEventListener('click', () => {
      const kind = button.dataset.showPhoto;
      const dialog = document.getElementById(kind === 'profile' ? 'profilePhotoDialog' : 'coverPhotoDialog');
      const image = document.getElementById(kind === 'profile' ? 'profilePhotoDialogImage' : 'coverPhotoDialogImage');
      const source = document.querySelector(kind === 'profile' ? '.profile-overview-avatar img' : '.profile-cover-image');
      if (!dialog || !image || !source) return;
      image.src = source.src;
      dialog.showModal();
      document.querySelectorAll('.profile-photo-menu').forEach((menu) => { menu.hidden = true; });
    });
  });
  document.querySelectorAll('[data-close-photo-dialog]').forEach((button) => {
    button.addEventListener('click', () => button.closest('dialog')?.close());
  });
  const membershipDetails = document.getElementById('membershipFormDetails');
  const profileAccordion = document.getElementById('profileEditor');
  const profileAccordionSummary = profileAccordion?.querySelector('summary');
  const profileAccordionState = profileAccordion?.querySelector('.profile-accordion-state');
  const syncProfileAccordion = () => {
    if (!profileAccordion) return;
    profileAccordionSummary?.setAttribute('aria-expanded', String(profileAccordion.open));
    if (profileAccordionState) profileAccordionState.textContent = profileAccordion.open ? 'Click to minimize' : 'Click to expand';
  };
  profileAccordion?.addEventListener('toggle', syncProfileAccordion);
  syncProfileAccordion();
  document.querySelector('[data-open-membership]')?.addEventListener('click', () => {
    if (!membershipDetails) return;
    membershipDetails.open = true;
    membershipDetails.querySelectorAll('input,select').forEach((field) => { field.required = true; });
    membershipDetails.scrollIntoView({ behavior: 'smooth', block: 'center' });
    membershipDetails.querySelector('input')?.focus({ preventScroll: true });
  });
  membershipDetails?.addEventListener('toggle', () => {
    if (membershipDetails.open) membershipDetails.querySelectorAll('input,select').forEach((field) => { field.required = true; });
    else if (membershipDetails.dataset.existingMember !== '1') membershipDetails.querySelectorAll('input,select').forEach((field) => { field.required = false; });
  });
  const profileCoverInput = document.getElementById('profile_cover');
  let profileCoverPreviewUrl = '';
  profileCoverInput?.addEventListener('change', () => {
    const file = profileCoverInput.files?.[0];
    if (!file) return;
    if (profileCoverPreviewUrl) URL.revokeObjectURL(profileCoverPreviewUrl);
    profileCoverPreviewUrl = URL.createObjectURL(file);
    let cover = document.querySelector('.profile-cover-image');
    if (!cover) {
      cover = document.createElement('img');
      cover.className = 'profile-cover-image';
      cover.alt = '';
      document.querySelector('.profile-cover-art')?.prepend(cover);
    }
    cover.src = profileCoverPreviewUrl;
  });

  // CONFIRM ACCOUNT CLOSURE
  const deleteAccountDialog = document.getElementById('deleteAccountDialog');
  const openDeleteAccount = document.querySelector('[data-open-delete-account]');
  const closeDeleteAccount = document.querySelector('[data-close-delete-account]');
  if (deleteAccountDialog && openDeleteAccount) {
    openDeleteAccount.addEventListener('click', () => {
      if (typeof deleteAccountDialog.showModal === 'function') deleteAccountDialog.showModal();
      else deleteAccountDialog.setAttribute('open', '');
      deleteAccountDialog.querySelector('input[name="current_password"]')?.focus();
    });
    closeDeleteAccount?.addEventListener('click', () => {
      if (typeof deleteAccountDialog.close === 'function') deleteAccountDialog.close();
      else deleteAccountDialog.removeAttribute('open');
    });
    deleteAccountDialog.addEventListener('click', (event) => {
      if (event.target === deleteAccountDialog && typeof deleteAccountDialog.close === 'function') deleteAccountDialog.close();
    });
  }

  // REPORT FILE PREVIEW AND SAVE-AS OPTIONS
  const filePreviewDialog = document.getElementById('filePreviewDialog');
  const openFilePreview = document.querySelector('[data-open-report-preview]');
  const filePreviewFormat = document.getElementById('filePreviewFormat');
  const filePreviewLayout = document.getElementById('filePreviewLayout');
  const filePreviewHint = document.getElementById('filePreviewHint');
  const filePreviewSheet = document.getElementById('filePreviewSheet');
  const saveReportFile = document.getElementById('saveReportFile');
  if (filePreviewDialog && openFilePreview && filePreviewFormat && filePreviewLayout && saveReportFile) {
    const previewTable = document.querySelector('#reportPreviewSource table');
    const previewTarget = document.getElementById('filePreviewTable');
    if (previewTable && previewTarget) previewTarget.replaceChildren(previewTable.cloneNode(true));
    const syncFilePreview = () => {
      const format = filePreviewFormat.value;
      const layout = filePreviewLayout.value;
      filePreviewLayout.disabled = format === 'csv';
      filePreviewHint.textContent = format === 'csv'
        ? 'CSV keeps the filtered table data; page layout does not apply.'
        : layout === 'landscape' ? 'Landscape is recommended for wide report tables.' : 'Portrait uses a narrower page; wide tables may wrap more.';
      filePreviewSheet.classList.toggle('portrait', format !== 'csv' && layout === 'portrait');
      filePreviewSheet.classList.toggle('landscape', format === 'csv' || layout === 'landscape');
      const url = new URL(window.location.href);
      url.searchParams.set('format', format);
      url.searchParams.set('layout', layout);
      saveReportFile.href = url.toString();
      saveReportFile.textContent = `Save ${format.toUpperCase()} file`;
    };
    openFilePreview.addEventListener('click', () => {
      syncFilePreview();
      if (typeof filePreviewDialog.showModal === 'function') filePreviewDialog.showModal();
      else filePreviewDialog.setAttribute('open', '');
    });
    filePreviewFormat.addEventListener('change', syncFilePreview);
    filePreviewLayout.addEventListener('change', syncFilePreview);
    document.querySelectorAll('[data-close-file-preview]').forEach((button) => button.addEventListener('click', () => {
      if (typeof filePreviewDialog.close === 'function') filePreviewDialog.close();
      else filePreviewDialog.removeAttribute('open');
    }));
    filePreviewDialog.addEventListener('click', (event) => {
      if (event.target === filePreviewDialog && typeof filePreviewDialog.close === 'function') filePreviewDialog.close();
    });
  }

  // CONSISTENT EYE BUTTON FOR PASSWORD FIELDS
  document.querySelectorAll('[data-password-toggle]').forEach((toggleButton) => {
    const passwordField = document.getElementById(toggleButton.getAttribute('aria-controls'));
    if (!passwordField) return;
    toggleButton.addEventListener('click', () => {
      const reveal = passwordField.type === 'password';
      passwordField.type = reveal ? 'text' : 'password';
      toggleButton.setAttribute('aria-label', reveal ? 'Hide password' : 'Show password');
      toggleButton.setAttribute('aria-pressed', reveal ? 'true' : 'false');
      toggleButton.innerHTML = reveal
        ? '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 3l18 18M10.6 10.6a2 2 0 002.8 2.8M9.9 5.2A10.7 10.7 0 0112 5c5.2 0 8.7 4.2 9.5 7-.3 1-1 2.1-2 3.1M6.2 6.2C3.9 7.7 2.7 10 2.5 12c.8 2.8 4.3 7 9.5 7 1.2 0 2.3-.2 3.3-.6"/></svg>'
        : '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M2.5 12S6 5 12 5s9.5 7 9.5 7-3.5 7-9.5 7-9.5-7-9.5-7z"/><circle cx="12" cy="12" r="3"/></svg>';
    });
  });

  // SIDEBAR COLLAPSE
  const body = document.body;
  const sidebarButton = document.getElementById('sidebarToggle');
  const sidebarBrand = document.getElementById('sidebarBrand');

  if (sidebarButton) {
    const saved = localStorage.getItem('readingRoomSidebarCollapsed');

    if (saved === 'true') {
      body.classList.add('sidebar-collapsed');
    }

    const syncButton = () => {
      const collapsed = body.classList.contains('sidebar-collapsed');

      sidebarButton.setAttribute('aria-expanded', String(!collapsed));
      sidebarButton.setAttribute(
        'aria-label',
        collapsed ? 'Expand sidebar' : 'Minimize sidebar'
      );
      sidebarButton.setAttribute(
        'title',
        collapsed ? 'Expand sidebar' : 'Minimize sidebar'
      );
      if (sidebarBrand) {
        sidebarBrand.setAttribute('aria-label', collapsed ? 'Expand sidebar' : 'The Reading Room home');
        sidebarBrand.setAttribute('title', collapsed ? 'Expand sidebar' : 'The Reading Room home');
      }
    };

    syncButton();

    sidebarButton.addEventListener('click', () => {
      body.classList.toggle('sidebar-collapsed');

      localStorage.setItem(
        'readingRoomSidebarCollapsed',
        String(body.classList.contains('sidebar-collapsed'))
      );

      syncButton();
    });

    if (sidebarBrand) {
      sidebarBrand.addEventListener('click', (event) => {
        if (!body.classList.contains('sidebar-collapsed')) return;
        event.preventDefault();
        body.classList.remove('sidebar-collapsed');
        localStorage.setItem('readingRoomSidebarCollapsed', 'false');
        syncButton();
      });
    }
  }

  // PROFILE PHOTO CROP AND OPTIMIZED UPLOAD
  let coverSourceImage = null;
  let coverCropReady = false;
  const coverCropApply = document.getElementById('coverCropApply');
  const coverPhotoStatus = document.getElementById('coverPhotoStatus');
  const photoInput = document.getElementById('profile_picture');
  const cropCanvas = document.getElementById('profileCropCanvas');
  const photoPreview = document.getElementById('profilePhotoPreview');
  const cropSection = document.getElementById('profileCropSection');
  const cropZoom = document.getElementById('profileCropZoom');
  const cropApply = document.getElementById('profileCropApply');
  const photoStatus = document.getElementById('profilePhotoStatus');
  if (photoInput && cropCanvas && photoPreview && cropZoom && cropApply) {
    const context = cropCanvas.getContext('2d');
    let sourceImage = null;
    let cropReady = false;
    let offsetX = 0;
    let offsetY = 0;
    let dragging = null;
    const drawCrop = () => {
      if (!sourceImage) return;
      const side = Math.min(sourceImage.width, sourceImage.height) / Number(cropZoom.value);
      const maxX = Math.max(0, (sourceImage.width - side) / 2);
      const maxY = Math.max(0, (sourceImage.height - side) / 2);
      const sx = Math.max(0, Math.min(sourceImage.width - side, maxX + offsetX * maxX));
      const sy = Math.max(0, Math.min(sourceImage.height - side, maxY + offsetY * maxY));
      context.clearRect(0, 0, cropCanvas.width, cropCanvas.height);
      context.drawImage(sourceImage, sx, sy, side, side, 0, 0, cropCanvas.width, cropCanvas.height);
      cropCanvas.hidden = false;
      cropReady = false;
    };
    photoInput.addEventListener('change', () => {
      const file = photoInput.files && photoInput.files[0];
      if (!file) return;
      if (!/^image\/(jpeg|png|webp)$/.test(file.type)) {
        photoStatus.textContent = 'Choose a JPG, PNG, or WebP image.';
        photoInput.value = '';
        return;
      }
      const reader = new FileReader();
      reader.onload = () => {
        const image = new Image();
        image.onload = () => {
          sourceImage = image;
          cropZoom.value = '1';
          offsetX = 0;
          offsetY = 0;
          cropReady = false;
          cropSection.hidden = false;
          photoStatus.textContent = 'Adjust the crop, then apply it before saving.';
          drawCrop();
          if (cropSection instanceof HTMLDialogElement && !cropSection.open) cropSection.showModal();
          else cropSection.scrollIntoView({ behavior: 'smooth', block: 'center' });
        };
        image.onerror = () => { photoStatus.textContent = 'This image could not be opened.'; };
        image.src = reader.result;
      };
      reader.readAsDataURL(file);
    });
    cropZoom.addEventListener('input', drawCrop);
    cropCanvas.addEventListener('pointerdown', (event) => {
      if (!sourceImage) return;
      dragging = { x: event.clientX, y: event.clientY };
      cropCanvas.setPointerCapture(event.pointerId);
    });
    cropCanvas.addEventListener('pointermove', (event) => {
      if (!dragging || !sourceImage) return;
      const side = Math.min(sourceImage.width, sourceImage.height) / Number(cropZoom.value);
      const maxX = Math.max(1, (sourceImage.width - side) / 2);
      const maxY = Math.max(1, (sourceImage.height - side) / 2);
      offsetX = Math.max(-1, Math.min(1, offsetX - ((event.clientX - dragging.x) * side / cropCanvas.clientWidth) / maxX));
      offsetY = Math.max(-1, Math.min(1, offsetY - ((event.clientY - dragging.y) * side / cropCanvas.clientHeight) / maxY));
      dragging = { x: event.clientX, y: event.clientY };
      drawCrop();
    });
    cropCanvas.addEventListener('pointerup', () => { dragging = null; });
    cropCanvas.addEventListener('pointercancel', () => { dragging = null; });
    cropApply.addEventListener('click', () => {
      if (!sourceImage) return;
      cropCanvas.toBlob((blob) => {
        if (!blob) { photoStatus.textContent = 'Could not prepare this crop. Try another image.'; return; }
        const croppedFile = new File([blob], 'profile-photo.jpg', { type: 'image/jpeg', lastModified: Date.now() });
        const transfer = new DataTransfer();
        transfer.items.add(croppedFile);
        photoInput.files = transfer.files;
        cropReady = true;
        const previewImage = new Image();
        previewImage.src = URL.createObjectURL(blob);
        previewImage.alt = 'Cropped profile photo preview';
        photoPreview.replaceChildren(previewImage);
        const overviewAvatar = document.querySelector('.profile-overview-avatar');
        if (overviewAvatar) {
          let overviewImage = overviewAvatar.querySelector('img');
          if (!overviewImage) { overviewImage = new Image(); overviewAvatar.prepend(overviewImage); }
          overviewImage.src = previewImage.src;
          overviewImage.alt = 'Profile photo preview';
        }
        const savePhotoButton = document.getElementById('saveProfilePhoto');
        if (savePhotoButton) savePhotoButton.disabled = false;
        photoStatus.textContent = 'Crop applied. Save your profile to upload this photo.';
      }, 'image/jpeg', 0.88);
    });
    const profileForm = photoInput.closest('form');
    if (profileForm) profileForm.addEventListener('submit', (event) => {
      if (sourceImage && !cropReady) {
        event.preventDefault();
        photoStatus.textContent = 'Apply the crop before saving your profile photo.';
        cropApply.focus();
        return;
      }
      if (coverSourceImage && !coverCropReady) {
        event.preventDefault();
        coverPhotoStatus.textContent = 'Apply the cover crop before saving your background.';
        coverCropApply.focus();
      }
    });
  }

  // LANDSCAPE COVER PHOTO CROP
  const coverCropSection = document.getElementById('coverCropSection');
  const coverCropCanvas = document.getElementById('coverCropCanvas');
  const coverCropZoom = document.getElementById('coverCropZoom');
  const coverCropIndicator = document.querySelector('.cover-crop-indicator');
  const coverCropIndicatorText = document.getElementById('coverCropIndicatorText');
  const coverCropPlaceholder = document.getElementById('coverCropPlaceholder');
  if (profileCoverInput && coverCropSection && coverCropCanvas && coverCropZoom && coverCropApply && coverPhotoStatus) {
    const coverContext = coverCropCanvas.getContext('2d');
    let coverOffsetX = 0;
    let coverOffsetY = 0;
    let coverDragging = null;
    const drawCoverCrop = () => {
      if (!coverSourceImage) return;
      const baseWidth = Math.min(coverSourceImage.width, coverSourceImage.height * 3);
      const width = baseWidth / Number(coverCropZoom.value);
      const height = width / 3;
      const maxX = Math.max(0, (coverSourceImage.width - width) / 2);
      const maxY = Math.max(0, (coverSourceImage.height - height) / 2);
      const sx = Math.max(0, Math.min(coverSourceImage.width - width, maxX + coverOffsetX * maxX));
      const sy = Math.max(0, Math.min(coverSourceImage.height - height, maxY + coverOffsetY * maxY));
      coverContext.clearRect(0, 0, coverCropCanvas.width, coverCropCanvas.height);
      coverContext.drawImage(coverSourceImage, sx, sy, width, height, 0, 0, coverCropCanvas.width, coverCropCanvas.height);
      coverCropCanvas.hidden = false;
      if (coverCropPlaceholder) coverCropPlaceholder.hidden = true;
      coverCropReady = false;
      coverCropIndicator?.classList.remove('is-applied');
      if (coverCropIndicatorText) coverCropIndicatorText.textContent = 'COVER AREA · ADJUSTING';
      coverPhotoStatus.classList.remove('is-applied');
      coverPhotoStatus.textContent = 'Position and zoom your image, then apply the crop.';
    };
    profileCoverInput.addEventListener('change', () => {
      const file = profileCoverInput.files?.[0];
      if (!file) return;
      if (!/^image\/(jpeg|png|webp)$/.test(file.type)) {
        coverPhotoStatus.textContent = 'Choose a JPG, PNG, or WebP image.';
        profileCoverInput.value = '';
        return;
      }
      const image = new Image();
      image.onload = () => {
        coverSourceImage = image;
        coverCropZoom.value = '1';
        coverOffsetX = 0;
        coverOffsetY = 0;
        coverCropReady = false;
        coverCropSection.hidden = false;
        coverPhotoStatus.classList.remove('is-applied');
        if (coverCropIndicatorText) coverCropIndicatorText.textContent = 'COVER AREA · ADJUSTING';
        coverPhotoStatus.textContent = 'Position and zoom your image, then apply the crop.';
        drawCoverCrop();
        if (coverCropSection instanceof HTMLDialogElement && !coverCropSection.open) coverCropSection.showModal();
        else coverCropSection.scrollIntoView({ behavior: 'smooth', block: 'center' });
      };
      image.onerror = () => { coverPhotoStatus.textContent = 'This image could not be opened.'; };
      image.src = URL.createObjectURL(file);
    });
    coverCropZoom.addEventListener('input', drawCoverCrop);
    coverCropCanvas.addEventListener('pointerdown', (event) => {
      if (!coverSourceImage) return;
      coverDragging = { x: event.clientX, y: event.clientY };
      coverCropCanvas.setPointerCapture(event.pointerId);
    });
    coverCropCanvas.addEventListener('pointermove', (event) => {
      if (!coverDragging || !coverSourceImage) return;
      const width = Math.min(coverSourceImage.width, coverSourceImage.height * 3) / Number(coverCropZoom.value);
      const height = width / 3;
      const maxX = Math.max(1, (coverSourceImage.width - width) / 2);
      const maxY = Math.max(1, (coverSourceImage.height - height) / 2);
      coverOffsetX = Math.max(-1, Math.min(1, coverOffsetX - ((event.clientX - coverDragging.x) * width / coverCropCanvas.clientWidth) / maxX));
      coverOffsetY = Math.max(-1, Math.min(1, coverOffsetY - ((event.clientY - coverDragging.y) * height / coverCropCanvas.clientHeight) / maxY));
      coverDragging = { x: event.clientX, y: event.clientY };
      drawCoverCrop();
    });
    coverCropCanvas.addEventListener('pointerup', () => { coverDragging = null; });
    coverCropCanvas.addEventListener('pointercancel', () => { coverDragging = null; });
    coverCropApply.addEventListener('click', () => {
      if (!coverSourceImage) return;
      coverCropCanvas.toBlob((blob) => {
        if (!blob) { coverPhotoStatus.textContent = 'Could not prepare this crop. Try another image.'; return; }
        const croppedFile = new File([blob], 'profile-cover.jpg', { type: 'image/jpeg', lastModified: Date.now() });
        const transfer = new DataTransfer();
        transfer.items.add(croppedFile);
        profileCoverInput.files = transfer.files;
        coverCropReady = true;
        const previewUrl = URL.createObjectURL(blob);
        const pickerPreview = document.querySelector('.profile-cover-picker img') || document.createElement('img');
        pickerPreview.src = previewUrl;
        pickerPreview.alt = 'Cropped cover photo preview';
        if (!pickerPreview.parentElement) document.querySelector('.profile-cover-picker .profile-cover-placeholder')?.replaceWith(pickerPreview);
        const headerCover = document.querySelector('.profile-cover-image') || document.createElement('img');
        headerCover.className = 'profile-cover-image';
        headerCover.alt = '';
        headerCover.src = previewUrl;
        if (!headerCover.parentElement) document.querySelector('.profile-cover-art')?.prepend(headerCover);
        coverPhotoStatus.textContent = 'Cover crop applied. Save your profile to upload it.';
        coverPhotoStatus.classList.add('is-applied');
        const saveCoverButton = document.getElementById('saveCoverPhoto');
        if (saveCoverButton) saveCoverButton.disabled = false;
        coverCropIndicator?.classList.add('is-applied');
        if (coverCropIndicatorText) coverCropIndicatorText.textContent = 'CROP APPLIED';
      }, 'image/jpeg', 0.9);
    });
  }

  // THEMED CONFIRMATION DIALOG
  let activeDialog = null;

  function showConfirmation(options) {
    return new Promise((resolve) => {
      if (activeDialog) {
        resolve(false);
        return;
      }

      const {
        title = 'Are you sure?',
        message = 'Do you want to continue?',
        action = 'Confirm',
        danger = false
      } = options;

      const overlay = document.createElement('div');
      overlay.className = 'rr-dialog-overlay';

      const dialog = document.createElement('section');
      dialog.className = 'rr-dialog';
      dialog.setAttribute('role', 'alertdialog');
      dialog.setAttribute('aria-modal', 'true');
      dialog.setAttribute('aria-labelledby', 'rr-dialog-title');
      dialog.setAttribute('aria-describedby', 'rr-dialog-message');

      const icon = document.createElement('div');
      icon.className = `rr-dialog-icon${danger ? ' is-danger' : ''}`;
      icon.setAttribute('aria-hidden', 'true');
      icon.textContent = danger ? '!' : '?';

      const heading = document.createElement('h2');
      heading.className = 'rr-dialog-title';
      heading.id = 'rr-dialog-title';
      heading.textContent = title;

      const description = document.createElement('p');
      description.className = 'rr-dialog-message';
      description.id = 'rr-dialog-message';
      description.textContent = message;

      const actions = document.createElement('div');
      actions.className = 'rr-dialog-actions';

      const cancelButton = document.createElement('button');
      cancelButton.type = 'button';
      cancelButton.className = 'button button-outline';
      cancelButton.textContent = 'Cancel';

      const confirmButton = document.createElement('button');
      confirmButton.type = 'button';
      confirmButton.className =
        `button ${danger ? 'rr-button-danger' : 'button-primary'}`;
      confirmButton.textContent = action;

      actions.append(cancelButton, confirmButton);
      dialog.append(icon, heading, description, actions);
      overlay.appendChild(dialog);
      document.body.appendChild(overlay);

      activeDialog = overlay;
      document.body.classList.add('rr-dialog-open');

      let finished = false;

      function close(result) {
        if (finished) return;
        finished = true;

        document.removeEventListener('keydown', handleKeydown);
        overlay.remove();
        document.body.classList.remove('rr-dialog-open');
        activeDialog = null;

        resolve(result);
      }

      function handleKeydown(event) {
        if (event.key === 'Escape') {
          event.preventDefault();
          close(false);
        }

        if (event.key === 'Tab') {
          event.preventDefault();

          if (document.activeElement === cancelButton) {
            confirmButton.focus();
          } else {
            cancelButton.focus();
          }
        }
      }

      cancelButton.addEventListener('click', () => close(false));
      confirmButton.addEventListener('click', () => close(true));

      overlay.addEventListener('click', (event) => {
        if (event.target === overlay) close(false);
      });

      document.addEventListener('keydown', handleKeydown);
      cancelButton.focus();
    });
  }


  // CONFIRM LOGOUT
  document.querySelectorAll('a[data-confirm-logout="true"]').forEach((link) => {
    link.addEventListener('click', async (event) => {
      event.preventDefault();

      const confirmed = await showConfirmation({
        title: 'Leaving The Reading Room?',
        message: 'Are you sure you want to log out of your account?',
        action: 'Yes, log out',
        danger: true
      });

      if (confirmed) {
        showLoadingScreen('Signing you out');
        window.location.href = link.href;
      }
    });
  });


  // CONFIRM ADD, EDIT, AND DELETE FORMS
  document.querySelectorAll('form[data-confirm-message]').forEach((form) => {
    form.addEventListener('submit', async (event) => {
      // Allow the submission after the user confirms.
      if (form.dataset.confirmed === 'true') {
        delete form.dataset.confirmed;
        return;
      }

      event.preventDefault();

      const submitButton = event.submitter;

      const confirmed = await showConfirmation({
        title: form.dataset.confirmTitle || 'Please confirm',
        message: form.dataset.confirmMessage || 'Do you want to continue?',
        action: form.dataset.confirmAction || 'Confirm',
        danger: form.dataset.confirmDanger === 'true'
      });

      if (!confirmed) return;

      form.dataset.confirmed = 'true';

      if (submitButton && submitButton.form === form) {
        form.requestSubmit(submitButton);
      } else {
        form.requestSubmit();
      }
    });
  });

  function showLoadingScreen(message = 'Please wait') {
    if (document.querySelector('.rr-loading-overlay')) return;
    const overlay = document.createElement('div');
    overlay.className = 'rr-loading-overlay';
    overlay.setAttribute('role', 'status');
    overlay.setAttribute('aria-live', 'assertive');
    const card = document.createElement('div');
    card.className = 'rr-loading-card';
    const spinner = document.createElement('span');
    spinner.className = 'rr-loading-spinner';
    spinner.setAttribute('aria-hidden', 'true');
    const label = document.createElement('strong');
    label.textContent = message;
    const detail = document.createElement('small');
    detail.textContent = 'The Reading Room is getting things ready';
    card.append(spinner, label, detail);
    overlay.appendChild(card);
    document.body.appendChild(overlay);
    // Keep the loader tied to the real page request, but reassure readers if
    // the server or connection takes more than a couple of seconds.
    window.setTimeout(() => {
      if (overlay.isConnected) {
        detail.textContent = 'Still working. A slow connection may take a little longer.';
      }
    }, 1800);
  }

  document.addEventListener('submit', (event) => {
    if (event.defaultPrevented || !(event.target instanceof HTMLFormElement)) return;
    if (event.target.dataset.noLoading === 'true') return;
    showLoadingScreen(event.target.dataset.loadingLabel || 'Saving your changes');
    event.target.classList.add('is-submitting');
    event.target.querySelectorAll('button[type="submit"], input[type="submit"]').forEach((button) => {
      button.disabled = true;
    });
  });

  // DOWNLOAD REPORTS WITH CLEAR PROGRESS AND COMPLETION FEEDBACK
  function showExportToast(message, isError = false) {
    document.querySelector('.export-toast')?.remove();
    const toast = document.createElement('div');
    toast.className = `export-toast${isError ? ' is-error' : ''}`;
    toast.setAttribute('role', isError ? 'alert' : 'status');
    toast.innerHTML = `<span class="export-toast-mark" aria-hidden="true">${isError ? '!' : '✓'}</span><span>${message}</span>`;
    document.body.appendChild(toast);
    window.setTimeout(() => toast.remove(), 4500);
  }

  document.addEventListener('click', async (event) => {
      const link = event.target.closest('a[data-export-download]');
      if (!link) return;
      event.preventDefault();
      if (link.dataset.downloading === 'true') return;
      link.dataset.downloading = 'true';
      const overlay = document.createElement('div');
      overlay.className = 'rr-loading-overlay';
      overlay.setAttribute('role', 'status');
      overlay.setAttribute('aria-live', 'assertive');
      overlay.innerHTML = '<div class="rr-loading-card"><span class="rr-loading-spinner" aria-hidden="true"></span><strong>Preparing your file</strong><small>Please wait while your report is generated</small></div>';
      document.body.appendChild(overlay);
      try {
        const response = await fetch(link.href, { credentials: 'same-origin', cache: 'no-store' });
        if (!response.ok) throw new Error('The report could not be prepared. Please try again.');
        const blob = await response.blob();
        const disposition = response.headers.get('Content-Disposition') || '';
        const match = disposition.match(/filename="?([^";]+)"?/i);
        const downloadUrl = new URL(link.href, window.location.href);
        const extension = downloadUrl.searchParams.get('format') || 'csv';
        const fallback = `library-report.${extension}`;
        const filename = match ? match[1] : fallback;
        const objectUrl = URL.createObjectURL(blob);
        const downloader = document.createElement('a');
        downloader.href = objectUrl;
        downloader.download = filename;
        document.body.appendChild(downloader);
        downloader.click();
        downloader.remove();
        window.setTimeout(() => URL.revokeObjectURL(objectUrl), 30000);
        overlay.remove();
        showExportToast('Your file has been downloaded.');
      } catch (error) {
        overlay.remove();
        showExportToast(error.message || 'The download failed. Please try again.', true);
      } finally {
        delete link.dataset.downloading;
      }
  });

  document.addEventListener('click', (event) => {
    if (event.defaultPrevented) return;
    const link = event.target.closest('a[href]');
    if (!link || link.target === '_blank' || link.hasAttribute('download') || link.dataset.confirmLogout === 'true') return;
    const destination = new URL(link.href, window.location.href);
    if (destination.origin !== window.location.origin || destination.pathname === window.location.pathname && destination.search === window.location.search) return;
    showLoadingScreen(link.dataset.loadingLabel || 'Opening your library page');
  });
});


